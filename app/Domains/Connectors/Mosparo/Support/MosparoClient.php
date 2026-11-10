<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Support;

use App\Domains\Connectors\Mosparo\Data\MosparoCredentials;
use App\Domains\Connectors\Mosparo\Exceptions\MosparoRequestFailedException;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Support\NetworkGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * mosparo's statistic API (documentation.mosparo.io/docs/api/statistic): one project's
 * valid and spam submissions, and the requests its security delayed or blocked, per day.
 * mosparo keeps 14 days.
 *
 * Each call is signed with the project's key pair: an HMAC-SHA256 of the path and its
 * arguments as JSON, keyed with the private key, sent with the public key. Calls go
 * through the network guard, https only, as the address is whatever the user typed.
 */
class MosparoClient
{
    public const int TIMEOUT_S = 15;

    public const int MAX_BODY_BYTES = 1_048_576;

    /** How far back mosparo keeps its submissions. */
    public const int KEPT_DAYS = 14;

    private const string STATISTIC_PATH = '/api/v1/statistic/by-date';

    public function __construct(
        private readonly NetworkGuard $guard,
    ) {
    }

    /**
     * The totals over the last $rangeSeconds (all mosparo keeps when null), and the same per
     * day, by the date in mosparo's own time zone. mosparo counts whole days: a range counts
     * from the start of the day it reaches back into.
     *
     * @return array{valid: int, spam: int, delayed: int, blocked: int, last_submission_at: string|null, days: array<string, array{valid: int, spam: int, delayed: int, blocked: int}>}
     *
     * @throws MosparoRequestFailedException
     */
    public function statistics(MosparoCredentials $credentials, ?int $rangeSeconds = null): array
    {
        $arguments = $rangeSeconds === null ? [] : ['range' => max(1, $rangeSeconds)];
        $url = rtrim($credentials->host, '/').self::STATISTIC_PATH.($arguments === [] ? '' : '?'.http_build_query($arguments));

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new MosparoRequestFailedException(__('connectors.mosparo.errors.not_https'));
        }

        $request = Http::retry(
            times: 3,
            sleepMilliseconds: 200,
            when: fn (?Throwable $e): bool => $e instanceof ConnectionException,
            throw: false,
        )->withoutRedirecting()->timeout(self::TIMEOUT_S)->connectTimeout(5)->acceptJson()
            ->withUserAgent('Lumberjack-mosparo/1')
            ->withHeaders(['Authorization' => self::authorization($credentials, self::STATISTIC_PATH, $arguments)]);

        try {
            $response = $this->guard->send($request, 'GET', $url, self::MAX_BODY_BYTES + 1, (bool) config('health.data_sources_allow_private', false));
        } catch (HealthExceptionInterface|ConnectionException $e) {
            throw new MosparoRequestFailedException(self::clean($e->getMessage()), previous: $e);
        }

        return self::parse($this->json($response));
    }

    /**
     * @param  array<mixed>  $body
     * @return array{valid: int, spam: int, delayed: int, blocked: int, last_submission_at: string|null, days: array<string, array{valid: int, spam: int, delayed: int, blocked: int}>}
     *
     * @throws MosparoRequestFailedException
     */
    private static function parse(array $body): array
    {
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new MosparoRequestFailedException(__('connectors.mosparo.errors.not_mosparo'));
        }

        $days = [];

        foreach (is_array($data['numbersByDate'] ?? null) ? $data['numbersByDate'] : [] as $date => $numbers) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && is_array($numbers)) {
                $days[$date] = self::numbers($numbers);
            }
        }

        ksort($days);

        return [
            ...self::numbers($data),
            'last_submission_at' => is_string($data['lastSubmissionAt'] ?? null) ? $data['lastSubmissionAt'] : null,
            'days' => $days,
        ];
    }

    /**
     * The value of an Authorization header for a call: the public key and the HMAC of the
     * path and its arguments, as mosparo checks it.
     *
     * @param  array<string, int|string>  $arguments
     */
    public static function authorization(MosparoCredentials $credentials, string $path, array $arguments): string
    {
        // An empty argument list is signed as an object, as mosparo serializes it.
        $payload = $arguments === [] ? '{}' : (string) json_encode($arguments);

        return base64_encode($credentials->publicKey.':'.hash_hmac('sha256', $path.$payload, $credentials->privateKey));
    }

    /**
     * @return array<mixed>
     *
     * @throws MosparoRequestFailedException
     */
    private function json(Response $response): array
    {
        if (strlen($response->body()) > self::MAX_BODY_BYTES) {
            throw new MosparoRequestFailedException(__('connectors.mosparo.errors.too_large'));
        }

        $data = json_decode($response->body(), true);
        $status = $response->status();

        // mosparo answers a refused key with 400 or 401 and says why.
        if (is_array($data) && ($data['error'] ?? false) === true) {
            throw new MosparoRequestFailedException(in_array($status, [400, 401, 403], true)
                ? __('connectors.mosparo.errors.unauthorized')
                : __('connectors.mosparo.errors.status', ['status' => $status]));
        }

        if ($status !== 200) {
            throw new MosparoRequestFailedException(match ($status) {
                401, 403 => __('connectors.mosparo.errors.unauthorized'),
                404 => __('connectors.mosparo.errors.not_mosparo'),
                429 => __('connectors.mosparo.errors.rate_limited'),
                default => __('connectors.mosparo.errors.status', ['status' => $status]),
            });
        }

        return is_array($data) ? $data : throw new MosparoRequestFailedException(__('connectors.mosparo.errors.not_mosparo'));
    }

    /**
     * @param  array<mixed>  $numbers
     * @return array{valid: int, spam: int, delayed: int, blocked: int}
     */
    private static function numbers(array $numbers): array
    {
        $count = fn (string $key): int => is_numeric($numbers[$key] ?? null) ? (int) $numbers[$key] : 0;

        return [
            'valid' => $count('numberOfValidSubmissions'),
            'spam' => $count('numberOfSpamSubmissions'),
            'delayed' => $count('numberOfDelayedRequests'),
            'blocked' => $count('numberOfBlockedRequests'),
        ];
    }

    /** Guzzle names the URL in what it says, which may hold the instance's address. */
    private static function clean(string $message): string
    {
        return mb_substr((string) preg_replace(['~\s*\(see [^)]*\)~', '~\s*(?:for )?\b[a-z][a-z0-9+.-]*://\S+~i'], '', $message), 0, 300);
    }
}
