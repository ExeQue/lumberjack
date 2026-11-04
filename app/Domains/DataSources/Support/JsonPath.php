<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Support;

use Flow\JSONPath\JSONPath as Evaluator;
use Throwable;

/**
 * What a widget reads from a fetched body: RFC 9535 JSONPath (filters, wildcards,
 * recursive descent), evaluated on the decoded body. A path that does not start with $
 * is read from the root, so "data.total" means "$.data.total". A path that does not
 * parse, or is longer than MAX_PATH, matches nothing.
 */
final class JsonPath
{
    /** The longest path read; widget options allow less (WidgetSchema). */
    public const int MAX_PATH = 500;

    /** The most matches a path gives, and so the most rows a list gets. */
    public const int MAX_MATCHES = 100;

    /**
     * Every value the path matches.
     *
     * @return list<mixed>
     */
    public static function find(mixed $data, string $path): array
    {
        $path = trim($path);

        if ($path === '' || strlen($path) > self::MAX_PATH) {
            return [];
        }

        try {
            $found = (new Evaluator($data))->find(str_starts_with($path, '$') ? $path : '$.'.$path)->getData();
        } catch (Throwable) {
            return [];
        }

        return array_slice(array_values((array) $found), 0, self::MAX_MATCHES);
    }

    /**
     * The one number the path matches: a JSON number or a numeric string. Null when it
     * matches nothing, something else or several values.
     */
    public static function number(mixed $data, string $path): ?float
    {
        $found = self::find($data, $path);

        return count($found) === 1 && is_numeric($found[0]) ? (float) $found[0] : null;
    }

    /**
     * The items the path matches (see items). Each item's label and value are read with
     * their own paths, relative to the item; without a label path a scalar item is its own label.
     *
     * @return list<array{label: string, value: float|string|null}>
     */
    public static function rows(mixed $data, string $path, ?string $labelPath, ?string $valuePath, int $limit): array
    {
        $rows = [];

        foreach (array_slice(self::items($data, $path), 0, min($limit, self::MAX_MATCHES)) as $item) {
            $label = $labelPath !== null && $labelPath !== '' ? (self::find($item, $labelPath)[0] ?? null) : $item;
            $value = $valuePath !== null && $valuePath !== '' ? (self::find($item, $valuePath)[0] ?? null) : null;

            $rows[] = [
                'label' => self::text($label),
                'value' => is_numeric($value) ? (float) $value : (is_scalar($value) ? self::text($value) : null),
            ];
        }

        return $rows;
    }

    /**
     * The items a list reads: the elements of the one array the path matches, or else each match.
     *
     * @return list<mixed>
     */
    public static function items(mixed $data, string $path): array
    {
        $found = self::find($data, $path);

        return count($found) === 1 && is_array($found[0]) && array_is_list($found[0]) ? $found[0] : $found;
    }

    private static function text(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value === null => '',
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };

        return mb_substr($text, 0, 200);
    }
}
