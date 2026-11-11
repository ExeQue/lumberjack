<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\DataSources\Actions\FetchDataSourceWindowAction;
use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Queries\ConnectedDataSourceQuery;
use App\Domains\DataSources\Queries\DataSourceValuesQuery;
use App\Domains\DataSources\Support\JsonPath;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Support\DnsResolver;
use App\Domains\Health\Support\DomainExpiryLookup;
use App\Domains\Health\Support\NetworkGuard;
use App\Domains\Health\Support\SmtpProbe;
use App\Domains\Health\Support\TcpProbe;
use App\Domains\Health\Support\TlsCertificateReader;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Runs one check Lumberjack owns and records what it found. Every connection goes to
 * an address the network guard allowed, and redirects are followed by hand, at most
 * three, each checked again. The timeout is for each request, so a check with
 * redirects waits at most four times it.
 */
class RunHealthCheckAction
{
    public const int MAX_REDIRECTS = 3;

    /** The longest any check waits, whatever it was saved with. */
    public const int MAX_TIMEOUT_S = 15;

    /** How much of a body an HTTP check reads to look for its text. */
    public const int MAX_BODY_BYTES = 1_048_576;

    /** How a data source check compares the value with its limits. */
    public const array OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    public function __construct(
        private readonly NetworkGuard $guard,
        private readonly DnsResolver $dns,
        private readonly TcpProbe $tcp,
        private readonly TlsCertificateReader $tls,
        private readonly DomainExpiryLookup $domains,
        private readonly SmtpProbe $smtp,
        private readonly DataSourceValuesQuery $values,
        private readonly ConnectedDataSourceQuery $connected,
        private readonly RecordHealthResultAction $recordHealthResultAction,
    ) {
    }

    /**
     * @param  bool  $afterFetch  run because its data source was just fetched, so it judges that fetch
     */
    public function execute(HealthCheck $check, bool $afterFetch = false): ?HealthResultData
    {
        $started = hrtime(true);

        try {
            $result = match ($check->type) {
                HealthCheckType::Http => $this->http($check),
                HealthCheckType::Ssl => $this->ssl($check),
                HealthCheckType::Tcp => $this->tcp($check),
                HealthCheckType::Dns => $this->dns($check),
                HealthCheckType::Heartbeat => $this->heartbeat($check),
                HealthCheckType::Domain => $this->domain($check),
                HealthCheckType::Smtp => $this->smtp($check),
                HealthCheckType::DataSource => $this->dataSource($check, $afterFetch),
                null => null,
            };
        } catch (HealthExceptionInterface|ConnectionException $e) {
            $result = new HealthResultData(HealthResultStatus::Failed, HealthResultData::message($e->getMessage()));
        }

        if ($result === null) {
            return null;
        }

        $result = new HealthResultData($result->status, $result->message, $result->metrics, $result->durationUs ?? (int) ((hrtime(true) - $started) / 1000), at: now()->toImmutable());
        $this->recordHealthResultAction->execute($check, $result);

        return $result;
    }

    private function http(HealthCheck $check): HealthResultData
    {
        $url = (string) $check->setting('url');
        $method = strtoupper((string) $check->setting('method', 'GET'));
        $timeout = min((int) $check->setting('timeout_s', 10), self::MAX_TIMEOUT_S);
        $started = hrtime(true);
        $response = $this->request($method, $url, $timeout);

        for ($hop = 1; $response->redirect() && $response->header('Location') !== ''; $hop++) {
            if ($hop > self::MAX_REDIRECTS) {
                return new HealthResultData(HealthResultStatus::Failed, __('health.results.too_many_redirects', ['max' => self::MAX_REDIRECTS]));
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
            $response = $this->request($method, $url, $timeout);
        }

        $ms = round((hrtime(true) - $started) / 1e6, 1);
        $expected = (int) $check->setting('expected_status', 200);
        $contains = (string) $check->setting('body_contains', '');
        $maxLatency = $check->setting('max_latency_ms');
        $metrics = ['latency_ms' => $ms, 'status_code' => $response->status()];

        return match (true) {
            $response->status() !== $expected => new HealthResultData(HealthResultStatus::Failed, __('health.results.http_status', ['expected' => $expected, 'got' => $response->status()]), $metrics),
            $contains !== '' && ! str_contains(substr($response->body(), 0, self::MAX_BODY_BYTES), $contains) => new HealthResultData(HealthResultStatus::Failed, __('health.results.http_body', ['text' => $contains]), $metrics),
            ($jsonFailure = $this->jsonFailure($check, $response, $metrics)) !== null => $jsonFailure,
            is_numeric($maxLatency) && $ms > (int) $maxLatency => new HealthResultData(HealthResultStatus::Warning, __('health.results.http_slow', ['ms' => $ms, 'max' => (int) $maxLatency]), $metrics),
            default => new HealthResultData(HealthResultStatus::Ok, __('health.results.http_ok', ['status' => $response->status(), 'ms' => $ms]), $metrics),
        };
    }

    /**
     * The failure when the JSON body is not what the check wants; null when it is, or when
     * it wants nothing. With no expected value, the path only has to match.
     *
     * @param  array<string, float|int>  $metrics
     */
    private function jsonFailure(HealthCheck $check, Response $response, array $metrics): ?HealthResultData
    {
        $path = (string) $check->setting('json_path', '');

        if ($path === '') {
            return null;
        }

        $body = json_decode($response->body(), true);

        if ($body === null) {
            return new HealthResultData(HealthResultStatus::Failed, __('health.results.http_not_json'), $metrics);
        }

        $found = JsonPath::find($body, $path);

        if ($found === []) {
            return new HealthResultData(HealthResultStatus::Failed, __('health.results.http_json_missing', ['path' => $path]), $metrics);
        }

        $expected = (string) $check->setting('json_expected', '');
        $values = array_map(fn (mixed $value): string => is_scalar($value) && ! is_bool($value) ? (string) $value : (string) json_encode($value), $found);

        return $expected === '' || in_array($expected, $values, true)
            ? null
            : new HealthResultData(HealthResultStatus::Failed, __('health.results.http_json_mismatch', ['path' => $path, 'expected' => $expected, 'got' => mb_substr(implode(', ', $values), 0, 200)]), $metrics);
    }

    private function request(string $method, string $url, int $timeout): Response
    {
        // Connects to the address the guard checked, and reads no more of the body than a check looks at.
        return $this->guard->send(Http::withoutRedirecting()
            ->timeout($timeout)
            ->connectTimeout(min(5, $timeout))
            ->withUserAgent('Lumberjack-Health/1'), $method, $url, self::MAX_BODY_BYTES);
    }

    private function ssl(HealthCheck $check): HealthResultData
    {
        $host = (string) $check->setting('host');
        $expires = $this->tls->expiresAt($this->guard->resolve($host), $host, (int) $check->setting('port', 443), 10);

        return $this->expiry($check, $expires, 'health.results.ssl_expires', warnDays: 14, failDays: 3);
    }

    private function domain(HealthCheck $check): HealthResultData
    {
        return $this->expiry($check, $this->domains->expiresAt((string) $check->setting('domain')), 'health.results.domain_expires', warnDays: 30, failDays: 7);
    }

    private function expiry(HealthCheck $check, CarbonInterface $expires, string $messageKey, int $warnDays, int $failDays): HealthResultData
    {
        $days = (int) floor(now()->diffInDays($expires, false));
        $metrics = ['days_left' => $days];
        $message = __($messageKey, ['days' => $days, 'date' => $expires->toDateString()]);

        return match (true) {
            $days <= (int) $check->setting('fail_days', $failDays) => new HealthResultData(HealthResultStatus::Failed, $message, $metrics),
            $days <= (int) $check->setting('warn_days', $warnDays) => new HealthResultData(HealthResultStatus::Warning, $message, $metrics),
            default => new HealthResultData(HealthResultStatus::Ok, $message, $metrics),
        };
    }

    private function smtp(HealthCheck $check): HealthResultData
    {
        $host = (string) $check->setting('host');
        $started = hrtime(true);
        $greeting = $this->smtp->check($this->guard->resolve($host), $host, (int) $check->setting('port'), (string) $check->setting('encryption', SmtpProbe::ENCRYPTION_STARTTLS), (float) min((int) $check->setting('timeout_s', 10), self::MAX_TIMEOUT_S));
        $ms = round((hrtime(true) - $started) / 1e6, 1);

        return new HealthResultData(HealthResultStatus::Ok, __('health.results.smtp_ok', ['ms' => $ms, 'greeting' => $greeting]), ['latency_ms' => $ms]);
    }

    private function tcp(HealthCheck $check): HealthResultData
    {
        $port = (int) $check->setting('port');
        $started = hrtime(true);
        $this->tcp->connect($this->guard->resolve((string) $check->setting('host')), $port, (float) min((int) $check->setting('timeout_s', 5), self::MAX_TIMEOUT_S));
        $ms = round((hrtime(true) - $started) / 1e6, 1);

        return new HealthResultData(HealthResultStatus::Ok, __('health.results.tcp_ok', ['port' => $port, 'ms' => $ms]), ['latency_ms' => $ms]);
    }

    private function dns(HealthCheck $check): HealthResultData
    {
        $type = (string) $check->setting('record_type', 'A');
        $normalize = fn (string $v): string => strtolower(rtrim(trim($v), '.'));
        $records = $this->dns->records((string) $check->setting('name'), $type);
        $expected = (string) $check->setting('expected', '');
        $found = implode(', ', array_slice($records, 0, 5));

        return match (true) {
            $records === [] => new HealthResultData(HealthResultStatus::Failed, __('health.results.dns_none', ['type' => $type])),
            $expected !== '' && ! in_array($normalize($expected), array_map($normalize, $records), true) => new HealthResultData(HealthResultStatus::Failed, __('health.results.dns_mismatch', ['expected' => $expected, 'found' => $found]), ['count' => count($records)]),
            default => new HealthResultData(HealthResultStatus::Ok, $found, ['count' => count($records)]),
        };
    }

    /**
     * A ping records its own ok; this only notices the one that did not come.
     */
    private function heartbeat(HealthCheck $check): ?HealthResultData
    {
        $last = $check->last_ping_at ?? $check->created_at ?? now();
        $deadline = $last->addMinutes((int) $check->interval_minutes + (int) $check->setting('grace_minutes', 5));

        return $deadline->isPast()
            ? new HealthResultData(HealthResultStatus::Failed, $check->last_ping_at !== null ? __('health.results.heartbeat_late', ['since' => $check->last_ping_at->toIso8601String()]) : __('health.results.heartbeat_never'), durationUs: 0)
            : null;
    }

    /**
     * Each fetch of the source (for a connected one: each pull of the check's period) runs
     * it with $afterFetch, so on its own schedule it only speaks when that cannot: a check
     * with no result yet (new, or its settings changed), or a source no longer fetched in
     * time. A source not fetched yet leaves it pending. Reading a connected source's period
     * is also what keeps it pulled.
     */
    private function dataSource(HealthCheck $check, bool $afterFetch): ?HealthResultData
    {
        $source = DataSource::query()->with('dataSourceConnection')->whereReadBy($check)->first();

        if ($source === null) {
            return new HealthResultData(HealthResultStatus::Failed, __('health.results.data_source_missing'), durationUs: 0);
        }

        $period = Period::tryFrom((string) $check->setting('period'));
        $path = (string) $check->setting('path');

        if ($source->format === DataSourceFormat::Connected) {
            // An app that no longer offers it, or a check saved without a period, has nothing to read.
            if ($source->archived_at !== null || $period === null) {
                return new HealthResultData(HealthResultStatus::Failed, __($period === null ? 'health.results.data_source_no_period' : 'health.results.data_source_not_offered'), durationUs: 0);
            }

            $window = $this->connected->window($source, $period);
            $wanted = $window->wanted_since ?? $window->requested_at;
            // A pull from before the period became wanted (a screen's, hours ago) is not this check's to judge or wait from.
            $fetched = $window->fetched_at?->gte($wanted) ? $window->fetched_at : null;
            $error = $window->error;
            $since = $fetched ?? $wanted;
            $span = 2 * FetchDataSourceWindowAction::stepSeconds($source, $period) + 60;
            $read = fn (): ?float => is_int($window->data) || is_float($window->data) ? (float) $window->data : null;
        } else {
            $fetched = $source->last_fetched_at;
            $error = $source->last_error;
            $since = $fetched ?? $source->created_at ?? now();
            $span = 2 * $source->interval_minutes * 60 + 60;
            $read = fn (): ?float => $this->values->number($source, $path);
        }

        $stale = $since->addSeconds($span)->isPast();

        if (! $stale && (($fetched === null && $error === null) || (! $afterFetch && $check->last_result_at !== null))) {
            return null;
        }

        $value = $stale || $error !== null ? null : $read();

        // A source that is asked but fails says why, however long it has failed.
        if ($value === null) {
            return new HealthResultData(HealthResultStatus::Failed, match (true) {
                $error !== null => __('health.results.data_source_error', ['error' => $error]),
                $stale => $fetched !== null ? __('health.results.data_source_stale', ['since' => $fetched->toIso8601String()]) : __('health.results.data_source_never'),
                default => __('health.results.data_source_no_value', ['path' => $path !== '' ? $path : $source->name]),
            }, durationUs: 0);
        }

        $operator = (string) $check->setting('operator', '>');
        $metrics = ['value' => $value];
        $breaks = fn (string $key): bool => is_numeric($limit = $check->setting($key)) && match ($operator) {
            '>' => $value > (float) $limit,
            '>=' => $value >= (float) $limit,
            '<' => $value < (float) $limit,
            '<=' => $value <= (float) $limit,
            '=' => $value === (float) $limit,
            default => $value !== (float) $limit,
        };
        $breached = fn (string $key): string => __('health.results.data_source_breached', ['value' => $value, 'operator' => $operator, 'limit' => $check->setting($key)]);

        return match (true) {
            $breaks('fail') => new HealthResultData(HealthResultStatus::Failed, $breached('fail'), $metrics, 0),
            $breaks('warn') => new HealthResultData(HealthResultStatus::Warning, $breached('warn'), $metrics, 0),
            default => new HealthResultData(HealthResultStatus::Ok, __('health.results.data_source_ok', ['value' => $value]), $metrics, 0),
        };
    }
}
