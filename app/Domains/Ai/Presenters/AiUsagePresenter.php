<?php

declare(strict_types=1);

namespace App\Domains\Ai\Presenters;

use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The AI usage pages' figures as props: totals against the period before, charts per
 * model, and the keys, owners and actors that cost the most.
 *
 * Keys and owners are read from the hourly totals; actors only exist in the daily ones,
 * so their figures count whole days: a 24 hour period shows yesterday and today.
 *
 * @phpstan-import-type Row from AiCostQuery
 * @phpstan-type Totals array{calls: int, errors: int, tokens: int, input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int, reasoning_tokens: int, cost_ours: float, cost_customer: float, cost: float, unpriced_tokens: int}
 * @phpstan-type Summary array{calls: int, errors: int, tokens: int, input_tokens: int, output_tokens: int, cache_read_tokens: int, cache_write_tokens: int, reasoning_tokens: int, cost_ours: float, cost_customer: float, cost: float, unpriced_tokens: int, previous: Totals}
 * @phpstan-type Series list<array{name: string, points: list<array{t: string, v: float|int}>}>
 */
class AiUsagePresenter
{
    public const int PER_PAGE = 10;

    /** Models a chart draws on their own; the rest share one line. */
    public const int CHART_MODELS = 5;

    public function __construct(private readonly AiCostQuery $costs)
    {
    }

    /**
     * Where the period starts in a table's totals, and where the one before it does.
     *
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(Period $period, string $table, ?CarbonImmutable $now = null): array
    {
        $now ??= now()->toImmutable();
        $daily = $table === AiCostQuery::DAILY;
        $from = $daily ? $period->since($now)->startOfDay() : $period->since($now)->startOfHour();
        // As many whole buckets before as the period spans up to the current one.
        $bucketNow = $daily ? $now->startOfDay() : $now->startOfHour();

        return [$from, $from->subSeconds((int) $from->diffInSeconds($bucketNow))];
    }

    /**
     * The period's totals, with the period before's to compare.
     *
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     * @param  array{fingerprint?: string, owner?: list<array{type: string, id: string}>, actor?: list<array{type: string, id: string}>}  $only
     * @return Summary
     */
    public function summary(Project $project, Period $period, ?string $environment, string $table = AiCostQuery::HOURLY, array $only = [], ?AiPriceBook $book = null, ?CarbonImmutable $now = null): array
    {
        $book ??= AiPriceBook::for($project);
        $now ??= now()->toImmutable();
        [$from, $before] = $this->window($period, $table, $now);

        return [
            ...self::totals($this->costs->total($project, $table, $from, null, $environment, $only, $book)),
            'previous' => $this->compared($project, $table, $before, $from, 'start', $environment, $only, $book, $now),
        ];
    }

    /**
     * What a stretch before now compares with the period running now, which holds the share
     * of its last hour (or day) gone by: whole buckets from $from to $until, and that share
     * of one more. The period before ends where the current one starts, so its extra share
     * is of the bucket before $from (start); a stretch a day or a week ago ends as long ago,
     * so it is of the bucket at $until (end). No bucket counts in the current period and in
     * the period before. One query: the buckets over the wider range, that one weighted.
     *
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     * @param  'start'|'end'  $partialAt  which side of the stretch takes the share
     * @param  array{fingerprint?: string|list<string>, owner?: list<array{type: string, id: string}>, actor?: list<array{type: string, id: string}>}  $only
     * @return Totals
     */
    public function compared(Project $project, string $table, CarbonImmutable $from, CarbonImmutable $until, string $partialAt, ?string $environment = null, array $only = [], ?AiPriceBook $book = null, ?CarbonImmutable $now = null): array
    {
        $size = self::bucketOf($table);
        $share = self::share($size, $now ?? now()->toImmutable());
        $partial = $partialAt === 'start' ? $from->subSeconds($size) : $until;
        [$start, $end] = $share > 0 ? ($partialAt === 'start' ? [$partial, $until] : [$from, $until->addSeconds($size)]) : [$from, $until];
        $summed = ['calls' => 0, 'errors' => 0, 'tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0, 'cost_ours' => 0.0, 'cost_customer' => 0.0, 'cost' => 0.0, 'unpriced_tokens' => 0];

        foreach ($this->costs->get($project, $table, $start, $end, $environment, ['bucket'], $only, $size, $book) as $row) {
            $weight = $row['bucket'] === $partial->getTimestamp() ? $share : 1.0;

            foreach (array_keys($summed) as $field) {
                $summed[$field] += $row[$field] * $weight;
            }
        }

        foreach ($summed as $field => $value) {
            $summed[$field] = str_starts_with($field, 'cost') ? round($value, 6) : (int) round($value);
        }

        /** @var Totals */
        return $summed;
    }

    /**
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     */
    private static function bucketOf(string $table): int
    {
        return $table === AiCostQuery::DAILY ? 86400 : 3600;
    }

    /** The share of the current bucket (hour or day) gone by, from 0 to 1. */
    private static function share(int $size, CarbonImmutable $now): float
    {
        $at = $now->getTimestamp();

        return ($at - intdiv($at, $size) * $size) / $size;
    }

    /**
     * Cost and tokens per bucket, one line per model: the costliest few, the rest as one.
     *
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     * @param  array{fingerprint?: string, owner?: list<array{type: string, id: string}>, actor?: list<array{type: string, id: string}>}  $only
     * @return array{cost: Series, tokens: Series}
     */
    public function chart(Project $project, Period $period, ?string $environment, string $table = AiCostQuery::HOURLY, array $only = [], ?AiPriceBook $book = null, ?CarbonImmutable $now = null): array
    {
        $now ??= now()->toImmutable();
        [$from] = $this->window($period, $table, $now);
        $size = self::bucketSize($period, $table);

        return $this->lines($this->costs->get($project, $table, $from, null, $environment, ['bucket', 'model'], $only, $size, $book), $from, $size, $now);
    }

    /**
     * A chart's bucket: the period's, but no finer than the totals (an hour, or a day).
     *
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     */
    public static function bucketSize(Period $period, string $table): int
    {
        return max($period->bucketSeconds(), $table === AiCostQuery::DAILY ? 86400 : 3600);
    }

    /**
     * Rows by bucket and model as a chart's lines: the costliest few models, the rest as one,
     * a point per bucket from the period's start to now.
     *
     * @param  list<Row>  $rows
     * @return array{cost: Series, tokens: Series}
     */
    public function lines(array $rows, CarbonImmutable $from, int $size, ?CarbonImmutable $now = null): array
    {
        $byModel = [];

        foreach ($rows as $row) {
            $byModel[$row['model'] ?? ''][] = $row;
        }

        uksort($byModel, fn (string $a, string $b): int => [array_sum(array_column($byModel[$b], 'cost')), array_sum(array_column($byModel[$b], 'tokens'))]
            <=> [array_sum(array_column($byModel[$a], 'cost')), array_sum(array_column($byModel[$a], 'tokens'))]);

        $other = (string) __('ui/ai.chart.other');
        $lines = [];

        foreach (array_values($byModel) as $i => $modelRows) {
            $name = $i < self::CHART_MODELS ? (string) ($modelRows[0]['model'] ?? '') : $other;
            $lines[$name] = [...($lines[$name] ?? []), ...$modelRows];
        }

        $start = intdiv($from->getTimestamp(), $size) * $size;
        $buckets = range($start, intdiv(($now ?? now())->getTimestamp(), $size) * $size, $size);
        $series = fn (string $metric): array => array_map(function (string $name) use ($lines, $buckets, $metric): array {
            $values = array_fill_keys($buckets, 0);

            foreach ($lines[$name] as $row) {
                $bucket = $row['bucket'] ?? -1;

                if (isset($values[$bucket])) {
                    $values[$bucket] += $row[$metric];
                }
            }

            return ['name' => $name, 'points' => array_map(fn (int $t): array => ['t' => CarbonImmutable::createFromTimestamp($t)->toIso8601String(), 'v' => $values[$t]], $buckets)];
        }, array_keys($lines));

        return ['cost' => $series('cost'), 'tokens' => $series('tokens')];
    }

    /**
     * The keys, owners or actors of the period, costliest first, one page of them, each with
     * what it cost in the period before.
     *
     * @param  'keys'|'owners'|'actors'  $table
     * @return array<string, mixed> a paginator's array
     */
    public function top(Project $project, Period $period, ?string $environment, string $table, string $search, int $page, string $path, ?AiPriceBook $book = null, ?CarbonImmutable $now = null): array
    {
        $book ??= AiPriceBook::for($project);
        [$dimension, $source] = match ($table) {
            'keys' => ['fingerprint', AiCostQuery::HOURLY],
            'owners' => ['owner', AiCostQuery::HOURLY],
            'actors' => ['actor', AiCostQuery::DAILY],
        };
        $now ??= now()->toImmutable();
        [$from, $before] = $this->window($period, $source, $now);
        $id = fn (array $row): string => match ($table) {
            'keys' => $row['fingerprint'] ?? '',
            'owners' => ($row['owner_type'] ?? '').AiCostQuery::SEPARATOR.($row['owner_id'] ?? ''),
            'actors' => ($row['actor_type'] ?? '').AiCostQuery::SEPARATOR.($row['actor_id'] ?? ''),
        };

        // Sorted, searched and paged in the database; only the page is priced against the period before.
        $page = max(1, $page);
        ['rows' => $rows, 'total' => $total] = $this->costs->top($project, $from, null, $environment, $dimension, $search, self::PER_PAGE, ($page - 1) * self::PER_PAGE, null, $book);

        // A page past the end (the list shrank, or a link was edited) shows the last one.
        if ($rows === [] && $page > 1) {
            $total = $this->costs->top($project, $from, null, $environment, $dimension, $search, 1, 0, null, $book)['total'];
            $page = max(1, (int) ceil($total / self::PER_PAGE));
            ['rows' => $rows, 'total' => $total] = $this->costs->top($project, $from, null, $environment, $dimension, $search, self::PER_PAGE, ($page - 1) * self::PER_PAGE, null, $book);
        }
        $previous = [];

        // A period within the current bucket spans no whole bucket before it, only that share.
        if ($before < $from && $rows !== []) {
            foreach ($this->costs->top($project, $before, $from, $environment, $dimension, among: array_map($id, $rows), book: $book)['rows'] as $row) {
                $previous[$id($row)] = $row['cost'];
            }
        }

        // As the totals: the period before also holds the share of the bucket before it gone by of the current one.
        $size = self::bucketOf($source);

        if (($share = self::share($size, $now)) > 0 && $rows !== []) {
            foreach ($this->costs->top($project, $before->subSeconds($size), $before, $environment, $dimension, among: array_map($id, $rows), book: $book)['rows'] as $row) {
                $previous[$id($row)] = ($previous[$id($row)] ?? 0.0) + $row['cost'] * $share;
            }
        }

        $keys = $table === 'keys'
            ? $project->aiKeys()->whereIn('fingerprint', array_filter(array_column($rows, 'fingerprint')))->get()->keyBy('fingerprint')
            : collect();

        $items = array_map(fn (array $row): array => [
            ...match ($table) {
                'keys' => ['key' => ($key = $keys->get($row['fingerprint'] ?? '')) instanceof AiKey ? self::key($key) : null],
                'owners' => ['reference' => self::reference($row['owner_type'] ?? '', $row['owner_id'] ?? '')],
                'actors' => ['reference' => self::reference($row['actor_type'] ?? '', $row['actor_id'] ?? '')],
            },
            ...self::totals($row),
            'previous_cost' => round($previous[$id($row)] ?? 0.0, 6),
        ], $rows);

        return new LengthAwarePaginator(
            $items,
            $total,
            self::PER_PAGE,
            $page,
            ['path' => $path, 'pageName' => $table.'_page'],
        )->withQueryString()->toArray();
    }

    /**
     * One owner's or actor's usage per key, costliest first.
     *
     * @param  AiCostQuery::HOURLY|AiCostQuery::DAILY  $table
     * @param  array{owner?: list<array{type: string, id: string}>, actor?: list<array{type: string, id: string}>}  $only
     * @return list<array<string, mixed>>
     */
    public function byKey(Project $project, Period $period, ?string $environment, string $table, array $only, ?AiPriceBook $book = null, ?CarbonImmutable $now = null): array
    {
        [$from] = $this->window($period, $table, $now);
        $rows = $this->costs->get($project, $table, $from, null, $environment, ['fingerprint'], $only, book: $book);
        $keys = $project->aiKeys()->whereIn('fingerprint', array_filter(array_column($rows, 'fingerprint')))->get()->keyBy('fingerprint');
        // ponytail: an owner or actor uses a handful of keys, so these are sorted here.
        usort($rows, fn (array $a, array $b): int => [$b['cost'], $b['tokens']] <=> [$a['cost'], $a['tokens']]);

        return array_map(fn (array $row): array => [
            'key' => ($key = $keys->get($row['fingerprint'] ?? '')) instanceof AiKey ? self::key($key) : null,
            ...self::totals($row),
        ], $rows);
    }

    /**
     * The price each model a key used since a moment is charged at now, or null for none:
     * per owner its calls named, as an owner's price applies to that owner's calls. Owner
     * is set only for calls naming another owner than the key's.
     *
     * @return list<array{provider: string, model: string, owner: array{type: string, id: string}|null, price: array<string, mixed>|null}>
     */
    public function keyPrices(Project $project, AiKey $key, CarbonImmutable $since, ?AiPriceBook $book = null, ?string $environment = null): array
    {
        $book ??= AiPriceBook::for($project);
        $prices = [];

        $rows = AiUsageHourly::query()
            ->where('project_id', $project->id)
            ->whereFingerprint($key->fingerprint)
            ->where('hour', '>=', $since)
            ->when($environment !== null)->where('environment', $environment)
            ->distinct()
            ->orderBy('model')
            ->get(['provider', 'model', 'owner_type', 'owner_id']);

        foreach ($rows as $row) {
            // The call's owner, else the key's, as the cost is worked out.
            [$type, $id] = $row->owner_type !== '' ? [$row->owner_type, $row->owner_id] : [$key->owner_type, $key->owner_id];
            $owner = $type !== null && ($type !== $key->owner_type || $id !== $key->owner_id) ? ['type' => $type, 'id' => (string) $id] : null;
            $price = $book->resolve($row->provider, $row->model, $key->fingerprint, $type, $id, now());

            $prices[$row->provider."\0".$row->model."\0".json_encode($owner)] = [
                'provider' => $row->provider,
                'model' => $row->model,
                'owner' => $owner,
                'price' => $price !== null ? self::price($price, [$key->fingerprint => $key]) : null,
            ];
        }

        return array_values($prices);
    }

    /**
     * A key as the pages show it.
     *
     * @return array{uuid: string, short_id: string, name: string|null, label: string|null, provider: string|null, owner: array{type: string, id: string}|null, payer: string|null}
     */
    public static function key(AiKey $key): array
    {
        return [
            'uuid' => $key->uuid,
            'short_id' => $key->shortId(),
            'name' => $key->name,
            'label' => $key->label,
            'provider' => $key->provider,
            'owner' => $key->owner_type !== null ? ['type' => $key->owner_type, 'id' => (string) $key->owner_id] : null,
            'payer' => $key->payer?->value,
        ];
    }

    /**
     * A call as the pages show it.
     *
     * @return array<string, mixed>
     */
    public static function call(AiCall $call): array
    {
        return [
            'id' => $call->id,
            'at' => $call->occurred_at->format('Y-m-d\TH:i:s.vP'),
            'provider' => $call->provider,
            'model' => $call->model,
            'operation' => $call->operation->value,
            'status' => $call->status->value,
            'error' => $call->error,
            'input_tokens' => $call->input_tokens,
            'output_tokens' => $call->output_tokens,
            'cache_read_tokens' => $call->cache_read_tokens,
            'cache_write_tokens' => $call->cache_write_tokens,
            'reasoning_tokens' => $call->reasoning_tokens,
            'duration_ms' => $call->duration_ms,
            'actor' => $call->actor_type !== null ? ['type' => $call->actor_type, 'id' => (string) $call->actor_id] : null,
            'trace_id' => $call->trace_id,
            'environment' => $call->environment,
        ];
    }

    /**
     * A price as the pages show it.
     *
     * @param  array<string, AiKey>  $keys  the project's keys by fingerprint, for a key's price
     * @return array<string, mixed>
     */
    public static function price(AiPrice $price, array $keys = []): array
    {
        $key = $price->scope === AiPriceScope::Key ? $keys[(string) $price->scope_id] ?? null : null;

        return [
            'uuid' => $price->uuid,
            'scope' => $price->scope->value,
            'scope_label' => $price->scope->label(),
            'team_wide' => $price->project_id === null,
            'owner' => $price->scope_type !== null ? ['type' => $price->scope_type, 'id' => (string) $price->scope_id] : null,
            'key' => $key !== null ? self::key($key) : null,
            'provider' => $price->provider,
            'model_pattern' => $price->model_pattern,
            'valid_from' => $price->valid_from->toIso8601String(),
            'input' => $price->input,
            'output' => $price->output,
            'cache_read' => $price->cache_read,
            'cache_write' => $price->cache_write,
            'reasoning' => $price->reasoning,
        ];
    }

    /**
     * @return array{type: string, id: string}|null null for none (an empty type)
     */
    private static function reference(string $type, string $id): ?array
    {
        return $type !== '' ? ['type' => $type, 'id' => $id] : null;
    }

    /**
     * A row's figures, without what it was summed by.
     *
     * @param  Row  $row
     * @return Totals
     */
    public static function totals(array $row): array
    {
        return [
            'calls' => $row['calls'],
            'errors' => $row['errors'],
            'tokens' => $row['tokens'],
            'input_tokens' => $row['input_tokens'],
            'output_tokens' => $row['output_tokens'],
            'cache_read_tokens' => $row['cache_read_tokens'],
            'cache_write_tokens' => $row['cache_write_tokens'],
            'reasoning_tokens' => $row['reasoning_tokens'],
            'cost_ours' => round($row['cost_ours'], 6),
            'cost_customer' => round($row['cost_customer'], 6),
            'cost' => round($row['cost'], 6),
            'unpriced_tokens' => $row['unpriced_tokens'],
        ];
    }
}
