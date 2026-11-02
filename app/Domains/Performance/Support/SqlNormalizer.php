<?php

declare(strict_types=1);

namespace App\Domains\Performance\Support;

/**
 * Collapses whitespace, literals and variable-length IN lists so the same query groups together.
 */
class SqlNormalizer
{
    public function normalize(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'in (?)', $sql) ?? $sql;

        return mb_substr($sql, 0, 10_000);
    }

    public function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }
}
