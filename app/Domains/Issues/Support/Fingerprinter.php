<?php

declare(strict_types=1);

namespace App\Domains\Issues\Support;

use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;

/**
 * Decides which errors are "the same" issue.
 */
class Fingerprinter
{
    public function fingerprint(IssueSignal $signal): string
    {
        if ($signal->customFingerprint !== null && $signal->customFingerprint !== '') {
            // Anyone with the public browser key can send one, so a browser's never meets
            // the server's own (schedule-failed:..., command-failed:...).
            return hash('sha256', ($signal->source === IssueSource::Browser ? 'browser|' : '').'custom|'.$signal->customFingerprint);
        }

        $parts = match ($signal->source) {
            IssueSource::Server => [$signal->title, ...$this->firstAppFrame($signal)],
            IssueSource::Browser => [$signal->title, $this->normalizeMessage($signal->message), ...$this->topFrame($signal)],
            IssueSource::Log => [$signal->logChannel ?? 'default', $this->normalizeMessage($signal->message)],
        };

        return hash('sha256', $signal->source->value.'|'.implode('|', $parts));
    }

    /**
     * Numbers, ids, uuids and hex strings vary between occurrences of the same problem.
     */
    public function normalizeMessage(string $message): string
    {
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $message) ?? $message;
        $message = preg_replace('/\b0x[0-9a-f]+\b|\b[0-9a-f]{16,}\b/i', '{hex}', $message) ?? $message;
        $message = preg_replace('/\d+(\.\d+)?/', '{n}', $message) ?? $message;

        return trim(mb_substr($message, 0, 500));
    }

    /**
     * @return list<string>
     */
    private function firstAppFrame(IssueSignal $signal): array
    {
        foreach ($signal->stacktrace as $frame) {
            if (($frame['in_app'] ?? false) === true) {
                return [$this->normalizePath((string) ($frame['file'] ?? '')), (string) ($frame['line'] ?? '')];
            }
        }

        return [$this->normalizePath((string) $signal->file), (string) $signal->line];
    }

    /**
     * @return list<string>
     */
    private function topFrame(IssueSignal $signal): array
    {
        $frame = $signal->stacktrace[0] ?? null;

        if ($frame === null) {
            return [$this->normalizePath((string) $signal->file)];
        }

        return [$this->normalizePath((string) ($frame['file'] ?? '')), (string) ($frame['line'] ?? '')];
    }

    /**
     * Release-specific hashes and query strings in bundle URLs change on every deploy.
     */
    private function normalizePath(string $path): string
    {
        $path = preg_replace('/[?#].*$/', '', $path) ?? $path;
        $path = preg_replace('/[.-][0-9a-f]{8,}(?=\.\w+$)/i', '', $path) ?? $path;

        return $path;
    }
}
