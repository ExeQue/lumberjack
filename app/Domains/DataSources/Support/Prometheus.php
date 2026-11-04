<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Support;

/**
 * What a widget reads from a Prometheus source. The text format 0.0.4 (and OpenMetrics,
 * leniently) is parsed into {families: {name: {type, samples: [{labels, value}]}}}, keyed
 * by sample name, so a histogram's _bucket, _sum and _count are each their own entry with
 * the histogram's type. A widget reads it with a selector in PromQL style:
 *
 *     name{label="v", other!="x", job=~"api.*", path!~"/health"}
 *     sum(selector)   sum by (label, ...) (selector)   sum(selector) by (label, ...)
 *
 * Regexes are anchored, as in Prometheus, and a missing label is the empty string. A
 * counter (and a histogram's or summary's _bucket, _sum and _count) is read as its
 * per-minute increase between two fetches, series by series, a drop counting as a reset
 * (as Prometheus' increase does); anything else as it is.
 */
final class Prometheus
{
    /** The most series a selector gives, and so the most rows a list gets. */
    public const int MAX_SERIES = 100;

    /** Sample name suffixes of a family whose name they extend (counter_total, histogram_bucket, ...). */
    private const array SUFFIXES = ['_total', '_bucket', '_sum', '_count', '_created', '_info', '_gsum', '_gcount'];

    /**
     * The samples of the text, by name, with their family's type; NaN and infinite values,
     * comments and what does not parse are left out, and so are timestamps and exemplars.
     *
     * @return array{families: array<string, array{type: string, samples: list<array{labels: array<string, string>, value: float}>}>}
     */
    public static function parse(string $text): array
    {
        $types = [];
        $families = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '# EOF') {
                break;
            }

            if (preg_match('/^#\s*TYPE\s+(\S+)\s+(\S+)/', $line, $m) === 1) {
                $types[$m[1]] = strtolower($m[2]) === 'unknown' ? 'untyped' : strtolower($m[2]);

                continue;
            }

            // Labels may hold "}" and escaped quotes; an exemplar or comment after the value is dropped.
            if ($line === '' || $line[0] === '#' || preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)\s*(?:\{((?:[^"}]|"(?:[^"\\\\]|\\\\.)*")*)\})?\s+(\S+)(?:\s+[^\s#]\S*)?\s*(?:#.*)?$/', $line, $m) !== 1) {
                continue;
            }

            [, $name, $labels, $value] = $m;
            $family = self::family($name, $types);
            $type = $family !== null ? $types[$family] : 'untyped';

            // OpenMetrics' creation times are not values.
            if (! is_numeric($value) || ! is_finite((float) $value) || $family !== null && $family !== $name && str_ends_with($name, '_created')) {
                continue;
            }

            preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/', $labels, $pairs, PREG_SET_ORDER);
            $families[$name] ??= ['type' => $type, 'samples' => []];
            $families[$name]['samples'][] = [
                'labels' => array_column(array_map(fn (array $p): array => [$p[1], strtr($p[2], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"])], $pairs), 1, 0),
                'value' => (float) $value,
            ];
        }

        return ['families' => $families];
    }

    /**
     * How many samples a parsed body holds.
     *
     * @param  array<string, mixed>  $parsed
     */
    public static function count(array $parsed): int
    {
        return array_sum(array_map(fn (array $f): int => count($f['samples']), (array) ($parsed['families'] ?? [])));
    }

    /**
     * The selector, parsed: the name, its matchers [label, op, value] and, wrapped in sum,
     * the labels it sums by ([] for all). Null when it does not parse.
     *
     * @return array{name: string, matchers: list<array{string, string, string}>, by: list<string>|null}|null
     */
    public static function selector(string $expression): ?array
    {
        $expression = trim($expression);
        $by = null;
        $labels = '\(\s*((?:[a-zA-Z_][a-zA-Z0-9_]*\s*,?\s*)*)\)';

        if (strlen($expression) > JsonPath::MAX_PATH) {
            return null;
        }

        if (preg_match('/^sum\s*(?:by\s*'.$labels.'\s*)?\((.*?)\)\s*(?:by\s*'.$labels.')?$/s', $expression, $m) === 1) {
            if ($m[1] !== '' && ($m[3] ?? '') !== '') {
                return null;
            }

            $by = array_values(array_filter(array_map('trim', explode(',', $m[1].($m[3] ?? ''))), fn (string $l): bool => $l !== ''));
            $expression = trim($m[2]);
        }

        if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)\s*(?:\{(.*)\})?$/s', $expression, $m) !== 1) {
            return null;
        }

        $matchers = [];
        $rest = trim($m[2] ?? '');
        $matcher = '/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(=~|!~|!=|=)\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')\s*(?:,\s*|$)/s';

        while ($rest !== '') {
            if (preg_match($matcher, $rest, $p) !== 1) {
                return null;
            }

            $value = stripcslashes(($p[4] ?? '') !== '' ? $p[4] : ($p[3] ?? ''));

            // A regex that does not compile matches nothing; so does the selector.
            if (str_contains($p[2], '~') && ! self::compiles($value)) {
                return null;
            }

            $matchers[] = [$p[1], $p[2], $value];
            $rest = substr($rest, strlen($p[0]));
        }

        return ['name' => $m[1], 'matchers' => $matchers, 'by' => $by];
    }

    /**
     * What the selector reads from the current body: per series (or per group, in sum)
     * its labels and value; for a counter the per-minute increase since the previous
     * body, $seconds before, where only the series both have count. Null when the
     * selector does not parse. Matched is how many series it found before the rate.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $previous
     * @return array{name: string, counter: bool, matched: int, grouped: bool, rows: list<array{labels: array<string, string>, value: float}>}|null
     */
    public static function evaluate(?array $current, ?array $previous, ?float $seconds, string $expression): ?array
    {
        $selector = self::selector($expression);

        if ($selector === null) {
            return null;
        }

        $family = $current['families'][$selector['name']] ?? null;
        $counter = self::cumulative($selector['name'], is_array($family) ? (string) $family['type'] : 'untyped');
        $series = array_values(array_filter(is_array($family) ? $family['samples'] : [], fn (array $s): bool => self::matches((array) $s['labels'], $selector['matchers'])));

        $matched = count($series);

        if ($counter) {
            $before = [];

            foreach ($previous['families'][$selector['name']]['samples'] ?? [] as $s) {
                $before[self::key((array) $s['labels'])] = (float) $s['value'];
            }

            $series = $seconds !== null && $seconds > 0 ? array_values(array_filter(array_map(function (array $s) use ($before, $seconds): ?array {
                $was = $before[self::key((array) $s['labels'])] ?? null;

                return $was === null ? null : [...$s, 'value' => ((float) $s['value'] >= $was ? (float) $s['value'] - $was : (float) $s['value']) / $seconds * 60];
            }, $series))) : [];
        }

        if ($selector['by'] !== null) {
            $groups = [];

            foreach ($series as $s) {
                $labels = [];

                foreach ($selector['by'] as $label) {
                    if ((string) ($s['labels'][$label] ?? '') !== '') {
                        $labels[$label] = (string) $s['labels'][$label];
                    }
                }

                $key = self::key($labels);
                $groups[$key] ??= ['labels' => $labels, 'value' => 0.0];
                $groups[$key]['value'] += (float) $s['value'];
            }

            $series = array_values($groups);
        }

        return [
            'name' => $selector['name'],
            'counter' => $counter,
            'matched' => $matched,
            'grouped' => $selector['by'] !== null && $selector['by'] !== [],
            'rows' => array_map(fn (array $s): array => ['labels' => (array) $s['labels'], 'value' => (float) $s['value']], array_slice($series, 0, self::MAX_SERIES)),
        ];
    }

    /**
     * The one number of an evaluation: what sum by gives when it gives one group,
     * otherwise every series summed. Null when nothing matched or sum by gave several.
     *
     * @param  array{name: string, counter: bool, matched: int, grouped: bool, rows: list<array{labels: array<string, string>, value: float}>}|null  $result
     */
    public static function number(?array $result): ?float
    {
        if ($result === null || $result['rows'] === [] || $result['grouped'] && count($result['rows']) !== 1) {
            return null;
        }

        return array_sum(array_column($result['rows'], 'value'));
    }

    /**
     * The rows of a list: a group by its labels' values, a series by its labels.
     *
     * @param  array{name: string, counter: bool, matched: int, grouped: bool, rows: list<array{labels: array<string, string>, value: float}>}|null  $result
     * @return list<array{label: string, value: float}>
     */
    public static function rows(?array $result, int $limit): array
    {
        return array_map(fn (array $r): array => [
            'label' => mb_substr(match (true) {
                $r['labels'] === [] => $result['name'] ?? '',
                $result['grouped'] ?? false => implode(' · ', $r['labels']),
                default => implode(', ', array_map(fn (string $k, string $v): string => "{$k}={$v}", array_keys($r['labels']), $r['labels'])),
            }, 0, 200),
            'value' => $r['value'],
        ], array_slice($result['rows'] ?? [], 0, min($limit, self::MAX_SERIES)));
    }

    /**
     * Whether a sample name of the type only ever grows, and so is read as a rate.
     */
    public static function cumulative(string $name, string $type): bool
    {
        return $type === 'counter' || in_array($type, ['histogram', 'summary'], true) && preg_match('/_(bucket|sum|count)$/', $name) === 1;
    }

    /**
     * The declared family a sample name belongs to: its own, or the one it extends.
     *
     * @param  array<string, string>  $types
     */
    private static function family(string $name, array $types): ?string
    {
        if (isset($types[$name])) {
            return $name;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix) && isset($types[substr($name, 0, -strlen($suffix))])) {
                return substr($name, 0, -strlen($suffix));
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<array{string, string, string}>  $matchers
     */
    private static function matches(array $labels, array $matchers): bool
    {
        foreach ($matchers as [$label, $op, $value]) {
            $actual = (string) ($labels[$label] ?? '');
            $ok = match ($op) {
                '=' => $actual === $value,
                '!=' => $actual !== $value,
                '=~' => preg_match(self::regex($value), $actual) === 1,
                default => preg_match(self::regex($value), $actual) !== 1,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** Whether the regex compiles, without the warning PCRE gives when it does not. */
    private static function compiles(string $pattern): bool
    {
        set_error_handler(fn (): bool => true);

        try {
            return preg_match(self::regex($pattern), '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /** Anchored, as Prometheus anchors a label regex. */
    private static function regex(string $pattern): string
    {
        return '~^(?:'.str_replace('~', '\~', $pattern).')$~su';
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function key(array $labels): string
    {
        ksort($labels);

        return (string) json_encode($labels);
    }
}
