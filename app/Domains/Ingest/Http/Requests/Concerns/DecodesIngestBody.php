<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Http\Requests\Concerns;

/**
 * Agents send gzip-compressed JSON; browsers send plain JSON as text/plain (sendBeacon).
 */
trait DecodesIngestBody
{
    public const int MAX_BYTES = 1_048_576;

    protected function prepareForValidation(): void
    {
        $this->decodeIngestBody();
    }

    protected function decodeIngestBody(): void
    {
        $raw = $this->getContent();

        if (strlen($raw) > self::MAX_BYTES) {
            abort(413, 'Batch too large.');
        }

        if (str_contains(strtolower((string) $this->header('Content-Encoding')), 'gzip')) {
            $raw = @gzdecode($raw, self::MAX_BYTES * 20);

            if ($raw === false) {
                abort(400, 'Body is not valid gzip.');
            }
        }

        $decoded = json_decode($raw, true);

        $this->replace(is_array($decoded) ? $decoded : []);
    }
}
