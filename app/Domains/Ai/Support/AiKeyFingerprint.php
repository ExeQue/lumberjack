<?php

declare(strict_types=1);

namespace App\Domains\Ai\Support;

use App\Domains\Projects\Models\Project;

/**
 * A key's fingerprint: an HMAC of the SHA-256 the app sent (of driver and key), with the
 * project's secret. Made where a batch comes in, before it is queued, so the hash itself
 * never reaches the queue or failed_jobs; the key lookup makes it the same way.
 */
final class AiKeyFingerprint
{
    public const string SHA256_PATTERN = '/^[0-9a-f]{64}$/iD';

    /**
     * @return string|null null when the hash is not 64 hex characters
     */
    public static function of(string $secret, mixed $sha256): ?string
    {
        if (! is_string($sha256) || preg_match(self::SHA256_PATTERN, $sha256) !== 1) {
            return null;
        }

        return hash_hmac('sha256', strtolower($sha256), $secret);
    }

    /**
     * The batch's events with each AI call's key hash swapped for its fingerprint. A
     * fingerprint the app sent itself is dropped: only one made here is trusted later.
     */
    public static function seal(?Project $project, mixed $events): mixed
    {
        if (! is_array($events)) {
            return $events;
        }

        $secret = null;

        foreach ($events as $i => $event) {
            if (! is_array($event) || ($event['type'] ?? null) !== 'ai_call' || ! is_array($event['key'] ?? null)) {
                continue;
            }

            $key = $event['key'];
            $sha256 = $key['sha256'] ?? null;
            unset($key['sha256'], $key['fingerprint']);

            if ($project !== null && $sha256 !== null) {
                $secret ??= $project->ai_key_secret;
                $key['fingerprint'] = self::of($secret, $sha256);
            }

            $events[$i]['key'] = $key;
        }

        return $events;
    }

    /**
     * The fingerprint seal() put on a queued call's key, or null.
     *
     * @param  array<mixed>  $key
     */
    public static function sealed(array $key): ?string
    {
        $fingerprint = $key['fingerprint'] ?? null;

        return is_string($fingerprint) && preg_match('/^[0-9a-f]{64}$/D', $fingerprint) === 1 ? $fingerprint : null;
    }
}
