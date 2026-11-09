<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Support\PullSignature;
use App\Domains\Health\Exceptions\ProbeFailedException;
use App\Domains\Health\Support\NetworkGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One signed GET to an app. Https only, to an address the network guard allowed, redirects
 * not followed and the answer capped. The token is only ever used to sign.
 */
class SendSignedPullAction
{
    public const int TIMEOUT_S = 15;

    public const int MAX_BODY_BYTES = 4_194_304;

    public function __construct(
        private readonly NetworkGuard $guard,
    ) {
    }

    /**
     * @param  array<string, int|string>  $query
     *
     * @throws ProbeFailedException|ConnectionException
     */
    public function execute(string $url, string $token, array $query): Response
    {
        $parts = parse_url($url);

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host'])) {
            throw new ProbeFailedException(__('data_sources.errors.not_https'));
        }

        $timestamp = now()->getTimestamp();

        return $this->guard->send(Http::withoutRedirecting()
            ->timeout(self::TIMEOUT_S)
            ->connectTimeout(5)
            ->withUserAgent('Lumberjack-Pull/1')
            ->acceptJson()
            ->withHeaders([
                'X-Lumberjack-Timestamp' => (string) $timestamp,
                'X-Lumberjack-Signature' => PullSignature::sign($token, 'GET', $parts['host'], $parts['path'] ?? '/', $query, $timestamp),
            ]), 'GET', $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), self::MAX_BODY_BYTES + 1, (bool) config('health.data_sources_allow_private', false));
    }

    /**
     * The JSON of a 200 answer.
     *
     * @return array<mixed>
     *
     * @throws ProbeFailedException
     */
    public function json(Response $response): array
    {
        if ($response->status() !== 200) {
            throw new ProbeFailedException(__('data_sources.errors.status', ['status' => $response->status()]));
        }

        if (strlen($response->body()) > self::MAX_BODY_BYTES) {
            throw new ProbeFailedException(__('data_sources.errors.too_large', ['max' => self::MAX_BODY_BYTES / 1024]));
        }

        $data = json_decode($response->body(), true);

        return is_array($data) ? $data : throw new ProbeFailedException(__('data_sources.errors.not_json'));
    }

    /** Guzzle names the URL in what it says; NUL is refused by text columns. */
    public static function cleanMessage(string $message): string
    {
        return mb_substr(str_replace("\0", '', (string) preg_replace(['~\s*\(see [^)]*\)~', '~\s*(?:for )?\b[a-z][a-z0-9+.-]*://\S+~i'], '', $message)), 0, 500);
    }
}
