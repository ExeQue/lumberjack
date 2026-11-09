<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Enums;

use App\Domains\Health\Exceptions\ProbeFailedException;

/**
 * The forms a connected source answers in. An app is not trusted to keep to them: what it
 * answers is rebuilt from these fields and held to these limits before it is stored.
 */
enum DataSourceShape: string
{
    case Number = 'number';
    case Series = 'series';
    case List = 'list';
    case Table = 'table';

    public const int MAX_POINTS = 1000;

    public const int MAX_ROWS = 100;

    public const int MAX_COLUMNS = 20;

    private const int MAX_TEXT = 500;

    /** The end of year 9999, for a date that can be shown. */
    private const int MAX_TIME = 253_402_300_799;

    /**
     * @throws ProbeFailedException
     */
    public function normalize(mixed $data): mixed
    {
        return match ($this) {
            self::Number => $data === null ? null : self::number($data),
            self::Series => array_map(fn (mixed $point): array => [
                'at' => is_array($point) && is_int($point['at'] ?? null) && $point['at'] >= 0 && $point['at'] <= self::MAX_TIME && array_key_exists('value', $point) ? $point['at'] : throw self::invalid(),
                'value' => ($point['value'] ?? null) === null ? null : self::number($point['value']),
            ], self::listOf($data, self::MAX_POINTS)),
            self::List => array_map(fn (mixed $row): array => [
                'label' => is_array($row) && array_key_exists('value', $row) ? self::text($row['label'] ?? null) : throw self::invalid(),
                'value' => self::cell($row['value'] ?? null),
            ], self::listOf($data, self::MAX_ROWS)),
            self::Table => self::table($data),
        };
    }

    /**
     * @return array{columns: list<string>, rows: list<list<int|float|string|null>>}
     */
    private static function table(mixed $data): array
    {
        if (! is_array($data)) {
            throw self::invalid();
        }

        $columns = array_map(self::text(...), self::listOf($data['columns'] ?? null, self::MAX_COLUMNS));

        return ['columns' => $columns, 'rows' => array_map(function (mixed $row) use ($columns): array {
            $cells = self::listOf($row, self::MAX_COLUMNS);

            return count($cells) === count($columns) ? array_map(self::cell(...), $cells) : throw self::invalid();
        }, self::listOf($data['rows'] ?? null, self::MAX_ROWS))];
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $data, int $max): array
    {
        return is_array($data) && array_is_list($data) && count($data) <= $max ? $data : throw self::invalid();
    }

    private static function number(mixed $value): int|float
    {
        return is_int($value) || (is_float($value) && is_finite($value)) ? $value : throw self::invalid();
    }

    private static function cell(mixed $value): int|float|string|null
    {
        return match (true) {
            $value === null => null,
            is_int($value), is_float($value) => self::number($value),
            default => self::text($value),
        };
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? mb_substr(str_replace("\0", '', $value), 0, self::MAX_TEXT) : throw self::invalid();
    }

    private static function invalid(): ProbeFailedException
    {
        return new ProbeFailedException(__('data_sources.errors.bad_shape'));
    }
}
