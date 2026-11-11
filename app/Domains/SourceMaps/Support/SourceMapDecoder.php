<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Support;

use InvalidArgumentException;

/**
 * Reads a version 3 source map and finds where a position in the bundle came from.
 * The mappings are decoded once into one packed string per generated line, 20 bytes
 * a segment, so a large map stays a few megabytes in memory.
 */
final class SourceMapDecoder
{
    private const string BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private const int SEGMENT_BYTES = 20;

    /**
     * @param  list<string>  $lines  per generated line: column, source, line, column, name (-1 for none)
     * @param  list<string>  $sources
     * @param  list<string|null>  $contents
     * @param  list<string>  $names
     */
    private function __construct(
        private readonly array $lines,
        private readonly array $sources,
        private readonly array $contents,
        private readonly array $names,
    ) {
    }

    public static function decode(string $json): self
    {
        $map = json_decode($json, true);

        if (! is_array($map) || ($map['version'] ?? null) !== 3 || ! is_string($map['mappings'] ?? null) || ! is_array($map['sources'] ?? null)) {
            throw new InvalidArgumentException('Not a version 3 source map.');
        }

        $root = is_string($map['sourceRoot'] ?? null) && $map['sourceRoot'] !== '' ? rtrim($map['sourceRoot'], '/').'/' : '';
        $sources = array_map(fn ($s): string => $root.$s, array_values(array_filter($map['sources'], 'is_string')));
        $contents = array_map(fn ($c): ?string => is_string($c) ? $c : null, array_values((array) ($map['sourcesContent'] ?? [])));
        $names = array_values(array_filter((array) ($map['names'] ?? []), 'is_string'));

        $digits = array_flip(str_split(self::BASE64));
        $source = $line = $column = $name = 0;
        $lines = [];

        foreach (explode(';', $map['mappings']) as $segments) {
            $generated = 0;
            $packed = '';

            foreach (explode(',', $segments) as $segment) {
                if ($segment === '') {
                    continue;
                }

                $fields = self::vlq($segment, $digits);
                $generated += $fields[0] ?? throw new InvalidArgumentException('An unfinished segment in the mappings.');

                if (count($fields) < 4) {
                    $packed .= pack('l5', $generated, -1, 0, 0, -1);

                    continue;
                }

                $source += $fields[1];
                $line += $fields[2];
                $column += $fields[3];

                if (isset($fields[4])) {
                    $name += $fields[4];
                }

                $packed .= pack('l5', $generated, $source, $line, $column, isset($fields[4]) ? $name : -1);
            }

            $lines[] = $packed;
        }

        return new self($lines, $sources, $contents, $names);
    }

    /**
     * Where a 1-based line and column of the bundle came from, as 1-based line and column.
     *
     * @return array{source: string, line: int, column: int, name: string|null, content: string|null}|null
     */
    public function lookup(int $line, int $column): ?array
    {
        $packed = $this->lines[$line - 1] ?? '';
        $column--;

        // The last segment that starts at or before the column.
        $low = 0;
        $high = intdiv(strlen($packed), self::SEGMENT_BYTES) - 1;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $segment = array_values((array) unpack('l5', $packed, $middle * self::SEGMENT_BYTES));

            if ($segment[0] <= $column) {
                $found = $segment;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        if ($found === null || ! isset($this->sources[$found[1]])) {
            return null;
        }

        return [
            'source' => $this->sources[$found[1]],
            'line' => $found[2] + 1,
            'column' => $found[3] + 1,
            'name' => $this->names[$found[4]] ?? null,
            'content' => $this->contents[$found[1]] ?? null,
        ];
    }

    /**
     * One segment's Base64 VLQ fields, each relative to the segment before it.
     *
     * @param  array<int|string, int>  $digits  digit characters become int keys
     * @return list<int>
     */
    private static function vlq(string $segment, array $digits): array
    {
        $fields = [];
        $value = 0;
        $shift = 0;

        for ($i = 0, $length = strlen($segment); $i < $length; $i++) {
            $digit = $digits[$segment[$i]] ?? throw new InvalidArgumentException('Invalid character in the mappings.');
            $value += ($digit & 31) << $shift;

            if (($digit & 32) !== 0) {
                $shift += 5;

                continue;
            }

            $fields[] = ($value & 1) === 1 ? -($value >> 1) : $value >> 1;
            $value = 0;
            $shift = 0;
        }

        return $fields;
    }
}
