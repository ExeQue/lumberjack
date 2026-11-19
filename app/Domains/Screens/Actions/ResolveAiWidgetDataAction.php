<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Data\WidgetSource;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Exceptions\UnknownWidgetSourceException;
use App\Domains\Shared\Support\LruCache;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The data of a status screen widget about AI usage (the ai_ sources), from the same
 * queries as the AI usage pages, summed over the scope's projects. Cost is in the team's
 * currency, given as the unit currency:<code>. A key is never named by its fingerprint:
 * by its name, label or short id, and on a share link by its short id alone; owners and
 * actors there are numbered. A screen asks for many widgets at once: each project's prices,
 * and its period's rows by hour and model, are read once for all of them.
 *
 * @phpstan-import-type Row from AiCostQuery
 *
 * Options read here: ai_payer (all, us, customer) for the cost stat, ai_tokens (total,
 * input, output) for the tokens stat, ai_spenders (keys, owners, actors) for the list,
 * ai_budget (a budget's uuid) and ai_key (a key's uuid, for the error rate), and compare
 * (previous, yesterday, last_week, none) for a stat.
 */
class ResolveAiWidgetDataAction
{
    /** A budget's share used that turns it amber, and red. */
    public const float BUDGET_AMBER = 0.8;

    public const float BUDGET_RED = 1.0;

    /** @var LruCache<mixed> the rows each set of projects, period and environment asked for */
    private LruCache $memo;

    /** @var array<int, AiPriceBook> by project id */
    private array $books = [];

    private ?CarbonImmutable $now = null;

    public function __construct(
        private readonly AiUsagePresenter $usage,
        private readonly AiCostQuery $costs,
        private readonly AiBudgetQuery $budgets,
    ) {
        $this->memo = new LruCache(50);
    }

    /**
     * @param  Collection<int, Project>  $projects  the scope's, within the team
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array<string, mixed>
     */
    public function execute(Team $team, WidgetType $type, WidgetSource $source, Collection $projects, WidgetScope $scope, array $options, array $limits, bool $aggregatesOnly): array
    {
        $money = 'currency:'.$team->currency;

        return match ($source->id) {
            'ai_cost' => [...($type === WidgetType::Chart ? $this->chart($projects, $scope, 'cost') : $this->stat($projects, $scope, $options, $limits, 'cost')), 'unit' => $money],
            'ai_tokens' => $type === WidgetType::Chart ? $this->chart($projects, $scope, 'tokens') : $this->stat($projects, $scope, $options, $limits, 'tokens'),
            'ai_spenders' => [...$this->spenders($projects, $scope, $options, $aggregatesOnly), 'unit' => $money],
            'ai_budget' => $this->budget($team, $type, $projects, $options, $aggregatesOnly),
            'ai_error_rate' => $this->errorRate($type, $projects, $scope, $options, $limits),
            default => throw UnknownWidgetSourceException::for($type, $source->id),
        };
    }

    /**
     * {value, previous, change, state, limits, sparkline}, as any stat: the period's total
     * and its line from the same rows as the chart's.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @param  'cost'|'tokens'  $metric
     * @return array<string, mixed>
     */
    private function stat(Collection $projects, WidgetScope $scope, array $options, array $limits, string $metric): array
    {
        $field = $metric === 'cost'
            ? match ($options['ai_payer'] ?? 'all') {
                'us' => 'cost_ours',
                'customer' => 'cost_customer',
                default => 'cost',
            }
        : match ($options['ai_tokens'] ?? 'total') {
            'input' => 'input_tokens',
            'output' => 'output_tokens',
            default => 'tokens',
        };
        $rows = $this->rows($projects, $scope);
        $value = (float) array_sum(array_column($rows, $field));
        $previous = $this->previous($projects, $scope, $options);
        $previous = $previous !== null ? (float) $previous[$field] : null;
        $sparkline = null;

        // The sparkline draws what the number counts (whose cost, which tokens), every model added up.
        if (($options['sparkline'] ?? true) && $projects->isNotEmpty()) {
            [$from] = $this->usage->window($scope->period, AiCostQuery::HOURLY, $this->now());
            $counted = array_map(fn (array $row): array => [...$row, 'cost' => (float) $row[$field]], $rows);
            $sparkline = [];

            foreach ($this->usage->lines($counted, $from, AiUsagePresenter::bucketSize($scope->period, AiCostQuery::HOURLY), $this->now())['cost'] as $series) {
                foreach ($series['points'] as $i => $point) {
                    $sparkline[$i] = ['t' => $point['t'], 'v' => ($sparkline[$i]['v'] ?? 0) + $point['v']];
                }
            }
        }

        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $previous !== null && $previous != 0 ? round($value / $previous - 1, 4) : null,
            'state' => $this->state($value, $limits),
            'limits' => $limits,
            'sparkline' => $sparkline,
        ];
    }

    /**
     * {interval, red, series}: one line per model, the costliest five, the rest as one.
     *
     * @param  Collection<int, Project>  $projects
     * @param  'cost'|'tokens'  $metric
     * @return array{interval: string, red: null, series: list<array{name: string, points: list<array{t: string, v: float|int}>}>|array{}}
     */
    private function chart(Collection $projects, WidgetScope $scope, string $metric): array
    {
        [$from] = $this->usage->window($scope->period, AiCostQuery::HOURLY, $this->now());
        $size = AiUsagePresenter::bucketSize($scope->period, AiCostQuery::HOURLY);
        // Every project's rows at once, so a model is ranked by what it cost in all of them.
        $lines = $projects->isEmpty() ? [] : $this->usage->lines($this->rows($projects, $scope), $from, $size, $this->now())[$metric];

        return [
            // No finer than the hourly totals.
            'interval' => $size % 86400 === 0 ? ($size / 86400).'d' : ($size / 3600).'h',
            'red' => null,
            'series' => $lines,
        ];
    }

    /**
     * The period's rows by bucket and model over the projects, read once for every widget
     * that asks with the same projects, period and environment.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<Row>
     */
    private function rows(Collection $projects, WidgetScope $scope): array
    {
        [$from] = $this->usage->window($scope->period, AiCostQuery::HOURLY, $this->now());
        $size = AiUsagePresenter::bucketSize($scope->period, AiCostQuery::HOURLY);

        /** @var list<Row> */
        return $this->memo->remember(
            'rows:'.implode(',', $projects->modelKeys()).":{$from->getTimestamp()}:{$scope->environment}:{$size}",
            function () use ($projects, $from, $size, $scope): array {
                $rows = [];

                foreach ($projects as $project) {
                    array_push($rows, ...$this->costs->get($project, AiCostQuery::HOURLY, $from, null, $scope->environment, ['bucket', 'model'], [], $size, $this->book($project)));
                }

                return $rows;
            },
        );
    }

    /**
     * The totals a stat compares with: the period before, or the same stretch a day or a week
     * ago; null when it compares with nothing.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @param  array{fingerprint?: string}  $only
     * @return array<string, int|float>|null
     */
    private function previous(Collection $projects, WidgetScope $scope, array $options, array $only = []): ?array
    {
        [$from, $before] = $this->usage->window($scope->period, AiCostQuery::HOURLY, $this->now());
        $shift = match ($options['compare'] ?? 'previous') {
            'none' => null,
            'yesterday' => 86400,
            'last_week' => 7 * 86400,
            default => 0,
        };

        if ($shift === null) {
            return null;
        }

        // The period before ends where this one starts; a day or a week ago ends as long ago.
        // Each holds as much of one more hour as has gone by of this one (see compared()).
        [$start, $end] = $shift === 0 ? [$before, $from] : [$from->subSeconds($shift), $this->now()->startOfHour()->subSeconds($shift)];

        /** @var array<string, int|float> */
        return $this->memo->remember(
            'previous:'.implode(',', $projects->modelKeys()).":{$shift}:{$start->getTimestamp()}:{$end->getTimestamp()}:{$scope->environment}:".json_encode($only),
            function () use ($projects, $scope, $start, $end, $shift, $only): array {
                $sum = ['calls' => 0, 'errors' => 0, 'tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'cost_ours' => 0.0, 'cost_customer' => 0.0];

                foreach ($projects as $project) {
                    $compared = $this->usage->compared($project, AiCostQuery::HOURLY, $start, $end, $shift === 0 ? 'start' : 'end', $scope->environment, $only, $this->book($project), $this->now());

                    foreach (array_keys($sum) as $field) {
                        $sum[$field] += $compared[$field];
                    }
                }

                return $sum;
            },
        );
    }

    /**
     * The totals between two moments over the projects.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array{fingerprint?: string}  $only
     * @return array<string, int|float>
     */
    private function totals(Collection $projects, WidgetScope $scope, CarbonImmutable $from, ?CarbonImmutable $until, array $only = []): array
    {
        $sum = ['calls' => 0, 'errors' => 0, 'tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'cost_ours' => 0.0, 'cost_customer' => 0.0];

        foreach ($projects as $project) {
            $total = $this->costs->total($project, AiCostQuery::HOURLY, $from, $until, $scope->environment, $only, $this->book($project));

            foreach (array_keys($sum) as $field) {
                $sum[$field] += $total[$field];
            }
        }

        return $sum;
    }

    /** The moment the screen is read at, the same for every widget on it. */
    private function now(): CarbonImmutable
    {
        return $this->now ??= now()->toImmutable();
    }

    /** Each project's prices once per request: a screen has the team's projects at most. */
    private function book(Project $project): AiPriceBook
    {
        return $this->books[$project->id] ??= AiPriceBook::for($project);
    }

    /**
     * {columns, rows}: the keys, key owners or actors that cost the most in the period. An
     * owner or actor is one row over every project, keys are each project's own.
     *
     * ponytail: an owner or actor merged from each project's top N can miss one that is just
     * below N in every project; exact over projects needs every row of each, read them if it matters.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function spenders(Collection $projects, WidgetScope $scope, array $options, bool $aggregatesOnly): array
    {
        $kind = in_array($options['ai_spenders'] ?? null, ['owners', 'actors'], true) ? $options['ai_spenders'] : 'keys';
        $dimension = ['keys' => 'fingerprint', 'owners' => 'owner', 'actors' => 'actor'][$kind];
        $limit = max(1, min(100, (int) ($options['limit'] ?? ResolveWidgetDataAction::LIST_LIMIT)));
        [$from] = $this->usage->window($scope->period, $kind === 'actors' ? AiCostQuery::DAILY : AiCostQuery::HOURLY, $this->now());
        $rows = [];

        foreach ($projects as $project) {
            $top = $this->costs->top($project, $from, null, $scope->environment, $dimension, limit: $limit, book: $this->book($project))['rows'];
            $fingerprints = array_values(array_filter(array_column($top, 'fingerprint')));
            $keys = $kind === 'keys' && $fingerprints !== []
                ? AiKey::query()->whereProject($project)->whereFingerprint($fingerprints)->get()->keyBy('fingerprint')
                : null;

            foreach ($top as $row) {
                $key = $keys?->get($row['fingerprint'] ?? '');
                [$type, $reference] = match ($kind) {
                    'owners' => [(string) $row['owner_type'], (string) $row['owner_id']],
                    'actors' => [(string) $row['actor_type'], (string) $row['actor_id']],
                    default => ['', $project->id.':'.$row['fingerprint']],
                };
                $id = $type."\0".$reference;
                $rows[$id] ??= [
                    'project' => $project->slug,
                    'label' => match (true) {
                        $kind === 'keys' => $key instanceof AiKey ? ($aggregatesOnly ? $key->shortId() : $key->displayName()) : null,
                        // Usage that names nobody is one row of its own, never numbered.
                        $type === '' => __('screens.ai_spenders.none.'.$kind),
                        default => $type.':'.$reference,
                    },
                    'named' => $kind === 'keys' || $type !== '',
                    'provider' => $key instanceof AiKey ? $key->provider : null,
                    'value' => 0.0,
                    'tokens' => 0,
                    'state' => null,
                ];
                $rows[$id]['value'] += $row['cost'];
                $rows[$id]['tokens'] += $row['tokens'];

                // The same owner or actor in several projects belongs to none of them.
                if ($rows[$id]['project'] !== $project->slug) {
                    $rows[$id]['project'] = null;
                }
            }
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [$b['value'], $b['tokens']] <=> [$a['value'], $a['tokens']]);
        $rows = array_slice($rows, 0, $limit);
        $number = 0;

        foreach ($rows as $i => $row) {
            // A share link names no owner or actor: they are numbered, costliest first.
            if ($aggregatesOnly && $kind !== 'keys' && $row['named']) {
                $rows[$i]['label'] = __('screens.ai_spenders.'.$kind, ['number' => ++$number]);
            }

            unset($rows[$i]['named']);
        }

        return ['columns' => ['label', 'value', 'tokens'], 'rows' => $rows];
    }

    /**
     * A stat: {value (used), state, limits, budget: {name, amount, share, projected, overrun, period}};
     * a gauge: the share used on a scale from 0 to 1, or past it. Its project must be one
     * of the widget's; any other budget, or none, shows nothing.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function budget(Team $team, WidgetType $type, Collection $projects, array $options, bool $aggregatesOnly): array
    {
        $limits = ['amber' => self::BUDGET_AMBER, 'red' => self::BUDGET_RED, 'low_is_bad' => false];
        $budget = is_string($options['ai_budget'] ?? null)
            ? AiBudget::query()->whereUuid($options['ai_budget'])->whereProjectIn($projects)->with(['project.team', 'targets'])->first()
            : null;

        if ($budget === null) {
            return $type === WidgetType::Gauge
                ? ['value' => null, 'min' => 0.0, 'max' => 1.0, 'state' => null, 'limits' => $limits]
                : ['value' => null, 'previous' => null, 'change' => null, 'state' => null, 'limits' => $limits, 'sparkline' => null];
        }

        $usage = $this->budgets->measure($budget, book: $this->book($budget->project));
        $share = $usage->amount > 0 ? round($usage->spent / $usage->amount, 4) : 0.0;
        $state = $this->state($share, $limits);

        if ($type === WidgetType::Gauge) {
            return ['value' => $share, 'min' => 0.0, 'max' => max(1.0, $share), 'state' => $state, 'limits' => $limits];
        }

        return [
            'unit' => $budget->basis === AiBudgetBasis::Cost ? 'currency:'.$team->currency : 'count',
            'value' => round($usage->spent, 4),
            'previous' => null,
            'change' => null,
            'state' => $state,
            'limits' => $limits,
            'sparkline' => null,
            'budget' => [
                // A share link shows a name given, never what it counts (keys, owners).
                'name' => $aggregatesOnly ? $budget->name : $budget->title(),
                'amount' => $usage->amount,
                'share' => $share,
                'projected' => $usage->elapsed >= AiBudgetUsage::PROJECTION_FROM ? round($usage->projected(), 4) : null,
                'overrun' => $usage->projectsOverrun(),
                'period' => $budget->period->value,
            ],
        ];
    }

    /**
     * The share of calls that failed, of the scope or of one key: a stat with its trend, or a gauge.
     *
     * @param  Collection<int, Project>  $projects
     * @param  array<string, mixed>  $options
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     * @return array<string, mixed>
     */
    private function errorRate(WidgetType $type, Collection $projects, WidgetScope $scope, array $options, array $limits): array
    {
        $only = [];

        if (is_string($options['ai_key'] ?? null)) {
            $key = AiKey::query()->whereUuid($options['ai_key'])->whereProjectIn($projects)->first();
            // A key outside the scope covers nothing, rather than every key.
            $projects = $projects->where('id', $key?->project_id)->values();
            $only = $key !== null ? ['fingerprint' => $key->fingerprint] : [];
        }

        $rate = fn (?array $totals): ?float => $totals !== null && $totals['calls'] > 0 ? round($totals['errors'] / $totals['calls'], 4) : null;
        // A key's own calls need a query of their own; the scope's are the chart's rows.
        $value = $rate($only === [] ? ['calls' => array_sum(array_column($this->rows($projects, $scope), 'calls')), 'errors' => array_sum(array_column($this->rows($projects, $scope), 'errors'))]
            : $this->totals($projects, $scope, $this->usage->window($scope->period, AiCostQuery::HOURLY, $this->now())[0], null, $only));
        $state = $value !== null ? $this->state($value, $limits) : null;

        if ($type === WidgetType::Gauge) {
            return ['value' => $value, 'min' => 0.0, 'max' => 1.0, 'state' => $state, 'limits' => $limits];
        }

        $previous = $rate($this->previous($projects, $scope, $options, $only));

        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $value !== null && $previous !== null && $previous != 0 ? round($value / $previous - 1, 4) : null,
            'state' => $state,
            'limits' => $limits,
            'sparkline' => null,
        ];
    }

    /**
     * @param  array{amber: float|null, red: float|null, low_is_bad: bool}  $limits
     */
    private function state(float $value, array $limits): ?string
    {
        if ($limits['amber'] === null && $limits['red'] === null) {
            return null;
        }

        $past = fn (?float $limit): bool => $limit !== null && ($limits['low_is_bad'] ? $value <= $limit : $value >= $limit);

        return $past($limits['red']) ? 'red' : ($past($limits['amber']) ? 'amber' : 'ok');
    }
}
