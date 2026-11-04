<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Queries\DataSourceValuesQuery;
use App\Domains\DataSources\Support\JsonPath;
use App\Domains\DataSources\Support\Prometheus;
use App\Domains\Screens\Enums\WidgetType;

/**
 * Help for writing an External JSON widget's JSONPath in the designer: the data source's
 * latest body, the paths in it a widget of the type can read, and what the given paths
 * read now, the way the widget reads them (JsonPath::number and rows). It reads the stored
 * body only, never the URL, and nothing of the source's URL or headers. For a Prometheus
 * source the same with a selector: its metrics and example selectors, the raw text, and
 * what the selector reads now, a counter per minute (see prometheus).
 */
class PreviewDataSourceAction
{
    /** How much of the pretty body is shown. */
    public const int BODY_BYTES = 20_000;

    /** How deep suggestions go into the body, and how many there are. */
    public const int MAX_DEPTH = 8;

    public const int MAX_SUGGESTIONS = 200;

    /** How many elements of an array suggestions go into. */
    public const int MAX_ITEMS = 20;

    /** The rows of a list shown as its result. */
    public const int ROWS = 5;

    public function __construct(private readonly DataSourceValuesQuery $dataSources)
    {
    }

    /**
     * @return array{fetched_at: string|null, body: string|null, truncated: bool, suggestions: list<array{path: string, sample: mixed, kind: string, type?: string}>, item_suggestions: list<array{path: string, sample: mixed, kind: string}>, result: array<string, mixed>|null}
     */
    public function execute(DataSource $source, WidgetType $type, string $path, ?string $labelPath, ?string $valuePath): array
    {
        $empty = ['fetched_at' => null, 'body' => null, 'truncated' => false, 'suggestions' => [], 'item_suggestions' => [], 'result' => null];

        if ($source->last_body === null) {
            return $empty;
        }

        if ($source->format === DataSourceFormat::Prometheus) {
            return [
                ...$empty,
                'fetched_at' => $source->last_ok_at?->toIso8601String(),
                'body' => mb_strcut($source->last_body, 0, self::BODY_BYTES),
                'truncated' => strlen($source->last_body) > self::BODY_BYTES,
                ...$this->prometheus($source, $type, $path),
            ];
        }

        $data = $this->dataSources->latest($source);
        // Decoded again as objects, so an empty object prints as {}, not [].
        $pretty = json_encode(json_decode($source->last_body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: $source->last_body;
        $list = $type === WidgetType::List;
        $items = $list && $path !== '' ? JsonPath::items($data, $path) : [];
        $suggestions = [];
        $itemSuggestions = [];
        $this->walk($data, '$', 0, $list ? 'array' : 'number', $suggestions);

        if (is_array($items[0] ?? null) && ! array_is_list($items[0])) {
            $this->walk($items[0], '$', 0, 'scalar', $itemSuggestions);
        }

        return [
            ...$empty,
            'fetched_at' => $source->last_ok_at?->toIso8601String(),
            'body' => mb_strcut($pretty, 0, self::BODY_BYTES),
            'truncated' => strlen($pretty) > self::BODY_BYTES,
            'suggestions' => $suggestions,
            'item_suggestions' => $itemSuggestions,
            'result' => match (true) {
                $path === '' => null,
                $list => [
                    'count' => count($items),
                    'reason' => $items === [] ? 'no_match' : null,
                    'rows' => JsonPath::rows($data, $path, $labelPath, $valuePath, self::ROWS),
                ],
                default => $this->number($data, $path),
            },
        ];
    }

    /**
     * Suggestions for a Prometheus selector, its metrics (sample: its series) and then a
     * selector per label value (and for a list, a sum by per label), and what the selector
     * reads now, as the widget reads it; counter says it is per minute.
     *
     * @return array{suggestions: list<array{path: string, sample: int, kind: string, type: string}>, result: array<string, mixed>|null}
     */
    private function prometheus(DataSource $source, WidgetType $type, string $path): array
    {
        $families = (array) (($this->dataSources->latest($source) ?? [])['families'] ?? []);
        $suggestions = [];
        $examples = [];

        foreach ($families as $name => $family) {
            $suggestions[] = ['path' => (string) $name, 'sample' => count($family['samples']), 'kind' => 'family', 'type' => (string) $family['type']];
            $values = [];

            foreach ($family['samples'] as $sample) {
                foreach ($sample['labels'] as $label => $value) {
                    $values[$label][$value] = ($values[$label][$value] ?? 0) + 1;
                }
            }

            foreach ($values as $label => $counts) {
                if ($type === WidgetType::List && count($counts) > 1) {
                    $examples[] = ['path' => "sum by ({$label}) ({$name})", 'sample' => count($counts), 'kind' => 'selector', 'type' => (string) $family['type']];
                }

                foreach ($counts as $value => $count) {
                    $examples[] = ['path' => $name.'{'.$label.'="'.addcslashes((string) $value, "\\\"\n").'"}', 'sample' => $count, 'kind' => 'selector', 'type' => (string) $family['type']];
                }
            }
        }

        $suggestions = array_slice([...$suggestions, ...$examples], 0, self::MAX_SUGGESTIONS);

        if ($path === '') {
            return ['suggestions' => $suggestions, 'result' => null];
        }

        $result = $this->dataSources->evaluate($source, $path);
        $reason = match (true) {
            $result === null => 'invalid',
            $result['rows'] === [] => $result['counter'] && $result['matched'] > 0 ? 'no_rate' : 'no_match',
            default => null,
        };
        $counter = $result['counter'] ?? false;

        if ($type === WidgetType::List) {
            return ['suggestions' => $suggestions, 'result' => [
                'count' => count($result['rows'] ?? []),
                'reason' => $reason,
                'counter' => $counter,
                'rows' => array_map(fn (array $r): array => [...$r, 'value' => round($r['value'], 4)], Prometheus::rows($result, self::ROWS)),
            ]];
        }

        $value = Prometheus::number($result);

        return ['suggestions' => $suggestions, 'result' => [
            'value' => $value !== null ? round($value, 4) : null,
            'count' => count($result['rows'] ?? []),
            'reason' => $reason ?? ($value === null ? 'several' : null),
            'counter' => $counter,
        ]];
    }

    /**
     * The one number a stat, gauge or chart reads, or why there is none.
     *
     * @return array{value: float|null, count: int, reason: string|null}
     */
    private function number(mixed $data, string $path): array
    {
        $found = JsonPath::find($data, $path);
        $value = JsonPath::number($data, $path);

        return ['value' => $value, 'count' => count($found), 'reason' => $value !== null ? null : match (count($found)) {
            0 => 'no_match',
            1 => 'not_number',
            default => 'several',
        }];
    }

    /**
     * Collects the paths of what a field can read, depth first: numbers (JSON numbers and
     * numeric strings, as JsonPath::number takes), arrays (sample: their length), or any
     * scalar (for a list item's label and value). Of an array the first MAX_ITEMS elements
     * are walked (for arrays, only the first); an array of objects also offers each field
     * across all of it, as [*].
     *
     * @param  'number'|'array'|'scalar'  $want
     * @param  list<array{path: string, sample: mixed, kind: string}>  $out
     */
    private function walk(mixed $value, string $path, int $depth, string $want, array &$out): void
    {
        if (count($out) >= self::MAX_SUGGESTIONS) {
            return;
        }

        if (! is_array($value)) {
            $number = is_numeric($value);

            if ($want === 'number' && $number || $want === 'scalar' && is_scalar($value)) {
                $out[] = ['path' => $path, 'sample' => $value, 'kind' => $number ? 'number' : 'text'];
            }

            return;
        }

        if (array_is_list($value) && $value !== []) {
            if ($want === 'array') {
                $out[] = ['path' => $path, 'sample' => count($value), 'kind' => 'array'];

                if (is_array($value[0]) && ! array_is_list($value[0]) && $depth < self::MAX_DEPTH) {
                    foreach ($value[0] as $key => $field) {
                        if (is_scalar($field) && count($out) < self::MAX_SUGGESTIONS) {
                            $out[] = ['path' => $path.'[*]'.self::key((string) $key), 'sample' => count($value), 'kind' => 'array'];
                        }
                    }
                }
            }

            // A list's arrays are alike, so its first item shows them; a number may be in any item.
            foreach (array_slice($value, 0, $want === 'array' ? 1 : self::MAX_ITEMS) as $i => $item) {
                if ($depth < self::MAX_DEPTH) {
                    $this->walk($item, $path."[{$i}]", $depth + 1, $want, $out);
                }
            }

            return;
        }

        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        foreach ($value as $key => $child) {
            $this->walk($child, $path.self::key((string) $key), $depth + 1, $want, $out);
        }
    }

    /** .key, or ['key'] for a key that is not an identifier. */
    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1 ? '.'.$key : "['".addcslashes($key, "'\\")."']";
    }
}
