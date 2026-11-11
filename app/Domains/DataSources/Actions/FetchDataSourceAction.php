<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\Ical;
use App\Domains\DataSources\Support\Prometheus;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Exceptions\ProbeFailedException;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Support\NetworkGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Fetches one data source and keeps what it answered: the raw body on the source, and the
 * parsed body in the history when it is small enough for charts to read back (for
 * Prometheus text, the parsed samples, Prometheus::parse, under the same cap; an iCalendar
 * feed only as the latest, as its history is of no use to a widget). Guarded like
 * the health checks Lumberjack runs: every connection goes to an address the network guard
 * allowed (health.allow_private), redirects are followed by hand, each checked again, and
 * the headers only go to the source's own origin. The timeout is for each request, so a
 * fetch with redirects waits at most four times it.
 */
class FetchDataSourceAction
{
    /** The largest body read; a larger one fails the fetch. */
    public const int MAX_BODY_BYTES = 1_048_576;

    /** The largest iCalendar feed read: one with years of history passes a megabyte. */
    public const int MAX_ICAL_BYTES = 4_194_304;

    /** The largest body kept per fetch in the history (for Prometheus: parsed, as JSON); a larger one is only kept as the latest. */
    public const int HISTORY_MAX_BYTES = 65_536;

    public function __construct(
        private readonly NetworkGuard $guard,
        private readonly RunHealthCheckAction $runHealthCheckAction,
    ) {
    }

    /**
     * Fetches it, then runs the health checks that read it, on this fetch as on a failed one.
     */
    public function execute(DataSource $source): void
    {
        $this->fetchAndKeep($source);

        foreach (HealthCheck::query()->whereReadsDataSource($source)->whereMonitored()->whereNotArchived()->get() as $check) {
            $this->runHealthCheckAction->execute($check, afterFetch: true);
        }
    }

    private function fetchAndKeep(DataSource $source): void
    {
        $now = now();
        $source->last_fetched_at = $now;
        $source->last_status_code = null;
        $parsed = null;

        try {
            [$response, $body] = $this->fetch($source);
            $source->last_status_code = $response->status();

            if (! $response->successful()) {
                throw new ProbeFailedException(__('data_sources.errors.status', ['status' => $response->status()]));
            }

            if (strlen($body) > self::maxBytes($source)) {
                throw new ProbeFailedException(__('data_sources.errors.too_large', ['max' => self::maxBytes($source) / 1024]));
            }

            if ($source->format === DataSourceFormat::Ical) {
                $body = str_replace("\0", '', mb_scrub($body, 'UTF-8'));

                if (! Ical::parses($body)) {
                    throw new ProbeFailedException(__('data_sources.errors.not_ical'));
                }
            } elseif ($source->format === DataSourceFormat::Prometheus) {
                // Text, not JSON: kept as UTF-8 without NUL, which a text column refuses.
                $body = str_replace("\0", '', mb_scrub($body, 'UTF-8'));
                $parsed = Prometheus::parse($body);

                if (Prometheus::count($parsed) === 0) {
                    throw new ProbeFailedException(__('data_sources.errors.no_samples'));
                }
            } else {
                try {
                    json_decode($body, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new ProbeFailedException(__('data_sources.errors.not_json'));
                }
            }
        } catch (HealthExceptionInterface|ConnectionException $e) {
            // Guzzle names the URL in what it says, and a URL can carry a key in its query.
            $source->last_error = HealthResultData::message(preg_replace(['~\s*\(see [^)]*\)~', '~\s*(?:for )?\b[a-z][a-z0-9+.-]*://\S+~i'], '', $e->getMessage()));
            $source->save();

            return;
        } catch (DecryptException) {
            // Headers saved under another APP_KEY: only this source fails.
            $source->last_error = __('data_sources.errors.headers_unreadable');
            $source->save();

            return;
        }

        $source->fill(['last_ok_at' => $now, 'last_error' => null, 'last_body' => $body])->save();

        if ($source->format === DataSourceFormat::Ical) {
            return;
        }

        $history = $parsed !== null ? (string) json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) : $body;

        if (strlen($history) <= self::HISTORY_MAX_BYTES) {
            // jsonb takes no \u0000 (one not itself escaped as \\u0000): the history keeps the body without it.
            $history = $parsed !== null ? $history : (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u0000/', '$1', $body);
            DB::table('data_source_fetches')->insert(['project_id' => $source->project_id, 'data_source_id' => $source->id, 'body' => $history, 'fetched_at' => $now]);
        }
    }

    /**
     * The final response and its body, of which one byte past the most is read so too large shows.
     *
     * @return array{Response, string}
     */
    private function fetch(DataSource $source): array
    {
        $url = $source->url;
        $origin = self::origin($url);
        $timeout = min($source->timeout_s, RunHealthCheckAction::MAX_TIMEOUT_S);
        $response = $this->request($source, $url, $timeout, true);

        for ($hop = 1; $response->redirect() && $response->header('Location') !== ''; $hop++) {
            if ($hop > RunHealthCheckAction::MAX_REDIRECTS) {
                throw new ProbeFailedException(__('health.results.too_many_redirects', ['max' => RunHealthCheckAction::MAX_REDIRECTS]));
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
            $response = $this->request($source, $url, $timeout, self::origin($url) === $origin);
        }

        return [$response, $response->body()];
    }

    private function request(DataSource $source, string $url, int $timeout, bool $withHeaders): Response
    {
        $headers = $withHeaders ? array_column($source->headers ?? [], 'value', 'name') : [];

        // Connects to the address the guard checked, not whatever a second lookup says.
        return $this->guard->send(Http::withoutRedirecting()
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withUserAgent('Lumberjack-DataSource/1')
            ->accept(match ($source->format) {
                DataSourceFormat::Prometheus => 'text/plain;version=0.0.4;q=1.0, */*;q=0.1',
                DataSourceFormat::Ical => 'text/calendar, */*;q=0.1',
                DataSourceFormat::Json, DataSourceFormat::Connected => 'application/json',
            })
            ->withHeaders($headers), 'GET', $url, self::maxBytes($source) + 1);
    }

    private static function maxBytes(DataSource $source): int
    {
        return $source->format === DataSourceFormat::Ical ? self::MAX_ICAL_BYTES : self::MAX_BODY_BYTES;
    }

    /**
     * Scheme, host and port, the port given or not.
     */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return $scheme.'://'.strtolower((string) ($parts['host'] ?? '')).':'.($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
}
