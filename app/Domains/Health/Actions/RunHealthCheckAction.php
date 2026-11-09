<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

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

    public function __construct(
        private readonly NetworkGuard $guard,
        private readonly DnsResolver $dns,
        private readonly TcpProbe $tcp,
        private readonly TlsCertificateReader $tls,
        private readonly DomainExpiryLookup $domains,
        private readonly SmtpProbe $smtp,
        private readonly RecordHealthResultAction $recordHealthResultAction,
    ) {
    }

    public function execute(HealthCheck $check): ?HealthResultData
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
}
