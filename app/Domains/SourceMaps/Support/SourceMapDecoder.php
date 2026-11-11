<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Support;

use InvalidArgumentException;

/**
 * Reads a version 3 source map and finds where a position in the bundle came from.
 * The mappings are read in one pass, without splitting them into arrays, into one packed
 * string per generated line, 20 bytes a segment: some four times the mappings' size, so a
 * 20 MB map can take 80 MB. bytes() says how much, for whoever keeps decoded maps around.
 */
final class SourceMapDecoder
{
    private const string BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private const int SEGMENT_BYTES = 20;

    private ?int $bytes = null;

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
        // A null source keeps its place: mappings point at sources by index.
        $sources = array_map(fn ($s): string => is_string($s) ? $root.$s : '', array_values($map['sources']));
        $contents = array_map(fn ($c): ?string => is_string($c) ? $c : null, array_values((array) ($map['sourcesContent'] ?? [])));
        $names = array_map(fn ($n): string => is_string($n) ? $n : '', array_values((array) ($map['names'] ?? [])));

        $digits = array_flip(str_split(self::BASE64));
        $mappings = $map['mappings'];
        unset($map);
        $source = $line = $column = $name = 0;
        $lines = [];
        $packed = '';
        $generated = 0;
        $fields = [];
        $value = $shift = 0;

        // One character at a time: an explode() of a large map's segments costs hundreds of megabytes.
        for ($i = 0, $length = strlen($mappings); $i <= $length; $i++) {
            $char = $i < $length ? $mappings[$i] : ';';

            if ($char !== ',' && $char !== ';') {
                $digit = $digits[$char] ?? throw new InvalidArgumentException('Invalid character in the mappings.');
                $value += ($digit & 31) << $shift;

                if (($digit & 32) !== 0) {
                    $shift += 5;

                    continue;
                }

                $fields[] = ($value & 1) === 1 ? -($value >> 1) : $value >> 1;
                $value = $shift = 0;

                continue;
            }

            if ($shift !== 0) {
                throw new InvalidArgumentException('An unfinished segment in the mappings.');
            }

            if ($fields !== []) {
                $generated += $fields[0];

                if (count($fields) < 4) {
                    $packed .= pack('l5', $generated, -1, 0, 0, -1);
                } else {
                    $source += $fields[1];
                    $line += $fields[2];
                    $column += $fields[3];

                    if (isset($fields[4])) {
                        $name += $fields[4];
                    }

                    $packed .= pack('l5', $generated, $source, $line, $column, isset($fields[4]) ? $name : -1);
                }

                $fields = [];
            }

            if ($char === ';') {
                $lines[] = $packed;
                $packed = '';
                $generated = 0;
            }
        }

        return new self($lines, $sources, $contents, $names);
    }

    /**
     * Roughly what it holds in memory, in bytes.
     */
    public function bytes(): int
    {
        return $this->bytes ??= array_sum(array_map(strlen(...), $this->lines))
            + array_sum(array_map(fn (?string $content): int => strlen((string) $content), $this->contents))
            + array_sum(array_map(strlen(...), $this->names));
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

        // A null source is no file to show; the minified frame stays.
        if ($found === null || ($this->sources[$found[1]] ?? '') === '') {
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
}
