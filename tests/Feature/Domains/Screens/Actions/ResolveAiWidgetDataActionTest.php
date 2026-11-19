<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Actions\ResolveAiWidgetDataAction;
use App\Domains\Screens\Actions\ResolveWidgetDataAction;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Shared\Enums\Period;
use Tests\Support\Shared\ReadsQueryCounts;

covers(ResolveAiWidgetDataAction::class);

uses(ReadsQueryCounts::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0, 30));
    $this->project = Project::factory()->create();
    $this->project->team->update(['currency' => 'EUR']);
    // 1 per million input tokens, output free: a million input tokens cost 1.
    AiPrice::factory()->forTeamOf($this->project)->model('*')->costing(1, 0)->create();
    $this->use = fn (int $millions, array $attributes = [], ?Project $project = null) => AiUsageHourly::factory()->forProject($project ?? $this->project)
        ->create(['input_tokens' => $millions * 1_000_000, 'output_tokens' => 1000, 'environment' => (string) microtime(true), ...$attributes]);
    $this->resolve = fn (WidgetType $type, string $source, array $options = [], ?WidgetScope $scope = null, bool $shared = false): array => app(ResolveWidgetDataAction::class)
        ->execute($this->project->fresh()->team, $type, $source, $scope ?? new WidgetScope(), $options, $shared);
});

describe('AI cost and tokens', function () {
    it('shows the period\'s cost in the team\'s currency against the period before, by who pays', function () {
        $customers = AiKey::factory()->forProject($this->project)->create(['payer' => AiPayer::Customer]);
        ($this->use)(3);
        AiUsageHourly::factory()->forKey($customers)->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        ($this->use)(2, ['hour' => now()->subDay()->subHour()->startOfHour()]);

        expect(($this->resolve)(WidgetType::Stat, 'ai_cost'))->toMatchArray(['unit' => 'currency:EUR', 'value' => 4.0, 'previous' => 2.0, 'change' => 1.0])
            ->and(($this->resolve)(WidgetType::Stat, 'ai_cost', ['ai_payer' => 'us'])['value'])->toBe(3.0)
            ->and(($this->resolve)(WidgetType::Stat, 'ai_cost', ['ai_payer' => 'customer'])['value'])->toBe(1.0)
            ->and(collect(($this->resolve)(WidgetType::Stat, 'ai_cost')['sparkline'])->sum('v'))->toEqual(4)
            ->and(collect(($this->resolve)(WidgetType::Stat, 'ai_cost', ['ai_payer' => 'us'])['sparkline'])->sum('v'))->toEqual(3)
            ->and(collect(($this->resolve)(WidgetType::Stat, 'ai_tokens', ['ai_tokens' => 'output'])['sparkline'])->sum('v'))->toEqual(1000)
            ->and(($this->resolve)(WidgetType::Stat, 'ai_tokens', ['ai_tokens' => 'output']))->toMatchArray(['unit' => 'count', 'value' => 1000.0]);
    });

    it('compares with the period before, the same time yesterday or last week, or nothing, holding as much of its last hour as has gone by of this one', function (string $at, string $compare, ?float $cost, ?float $rate) {
        $this->travelTo(now()->setTimeFromTimeString($at));
        // Over the last hour: 11:00 until now; the period before is 10:00 to 11:00 and that share of 09:00.
        $hour = new WidgetScope(period: Period::Hour);
        ($this->use)(4, ['hour' => now()->setTime(12, 0), 'calls' => 10, 'errors' => 2]);
        ($this->use)(4, ['hour' => now()->setTime(11, 0), 'calls' => 10, 'errors' => 0]);
        ($this->use)(2, ['hour' => now()->setTime(10, 0), 'calls' => 10, 'errors' => 1]);
        ($this->use)(6, ['hour' => now()->setTime(9, 0), 'calls' => 10, 'errors' => 4]);
        ($this->use)(1, ['hour' => now()->subDay()->setTime(11, 0), 'calls' => 10, 'errors' => 5]);
        ($this->use)(8, ['hour' => now()->subWeek()->setTime(11, 0), 'calls' => 10, 'errors' => 10]);
        ($this->use)(50, ['hour' => now()->subDay()->setTime(12, 0), 'calls' => 10, 'errors' => 0]);
        ($this->use)(50, ['hour' => now()->subWeek()->setTime(12, 0), 'calls' => 10, 'errors' => 0]);

        $costs = ($this->resolve)(WidgetType::Stat, 'ai_cost', ['compare' => $compare], $hour)['previous'];
        $rates = ($this->resolve)(WidgetType::Stat, 'ai_error_rate', ['compare' => $compare], $hour)['previous'];

        expect($cost === null ? $costs : round($costs, 4))->toBe($cost)
            ->and($rates)->toBe($rate);
    })->with([
        'the period before, just past the hour' => ['12:00:30', 'previous', 2.05, 0.1],
        'the period before, half past' => ['12:30:00', 'previous', 5.0, 0.2],
        'yesterday, just past the hour' => ['12:00:30', 'yesterday', 1.4167, 0.5],
        'yesterday, half past' => ['12:30:00', 'yesterday', 26.0, 0.3333],
        'last week, just past the hour' => ['12:00:30', 'last_week', 8.4167, 1.0],
        'last week, half past' => ['12:30:00', 'last_week', 33.0, 0.6667],
        'nothing' => ['12:30:00', 'none', null, null],
    ]);

    it('never counts a bucket in the period and in the period before', function () {
        $this->travelTo(now()->setTime(12, 30));
        // The period's first hour only.
        ($this->use)(4, ['hour' => now()->setTime(11, 0)]);

        expect(($this->resolve)(WidgetType::Stat, 'ai_cost', [], new WidgetScope(period: Period::Hour)))->toMatchArray(['value' => 4.0, 'previous' => 0.0]);
    });

    it('keeps the rows of each environment and period apart within one screen', function () {
        ($this->use)(1, ['environment' => 'production']);
        ($this->use)(2, ['environment' => 'staging', 'hour' => now()->subHours(5)->startOfHour()]);
        $action = app(ResolveWidgetDataAction::class);
        $cost = fn (WidgetScope $scope): float => $action->execute($this->project->fresh()->team, WidgetType::Stat, 'ai_cost', $scope, ['compare' => 'none'])['value'];

        expect($cost(new WidgetScope(environment: 'production')))->toBe(1.0)
            ->and($cost(new WidgetScope(environment: 'staging')))->toBe(2.0)
            ->and($cost(new WidgetScope()))->toBe(3.0)
            ->and($cost(new WidgetScope(period: Period::Hour)))->toBe(1.0);
    });

    it('keeps the period before and yesterday apart within one screen, though they span the same whole hours', function () {
        $this->travelTo(now()->setTime(12, 30));
        // Over 24 hours both span 12:00 two days ago until 12:00 yesterday; the period before
        // adds half of the hour before it, yesterday half of the hour after it.
        ($this->use)(1, ['hour' => now()->subDays(2)->setTime(15, 0)]);
        ($this->use)(4, ['hour' => now()->subDays(2)->setTime(11, 0)]);
        ($this->use)(8, ['hour' => now()->subDay()->setTime(12, 0)]);
        $action = app(ResolveWidgetDataAction::class);
        $previous = fn (string $compare): float => $action->execute($this->project->fresh()->team, WidgetType::Stat, 'ai_cost', new WidgetScope(), ['compare' => $compare])['previous'];

        expect($previous('previous'))->toBe(3.0)
            ->and($previous('yesterday'))->toBe(5.0);
    });

    it('reads each project\'s prices and rows once for every AI widget of a screen', function () {
        $projects = [$this->project, ...Project::factory()->count(2)->create(['team_id' => $this->project->team_id])];

        foreach ($projects as $project) {
            ($this->use)(1, [], $project);
        }

        $team = $this->project->fresh()->team;
        $team->projects;
        // A screen's widgets share one action, as ResolveScreenDataAction does.
        $screen = fn (array $widgets): Closure => function () use ($team, $widgets): void {
            $action = app(ResolveWidgetDataAction::class);

            foreach ($widgets as [$type, $source]) {
                $action->execute($team, $type, $source, new WidgetScope(), ['compare' => 'none']);
            }
        };

        $one = $this->queriesRunBy($screen([[WidgetType::Chart, 'ai_cost']]));
        $all = $this->queriesRunBy($screen([
            [WidgetType::Chart, 'ai_cost'],
            [WidgetType::Chart, 'ai_tokens'],
            [WidgetType::Stat, 'ai_cost'],
            [WidgetType::Stat, 'ai_tokens'],
            [WidgetType::Gauge, 'ai_error_rate'],
        ]));

        // Per project its price book and the period's rows (AiCostQuery's two queries), whatever the widgets.
        expect($all)->toBe($one)->and($one)->toBe(3 * count($projects));

        // Compared with the period before: once more per project (two queries), for every stat.
        $compared = fn (array $widgets): Closure => function () use ($team, $widgets): void {
            $action = app(ResolveWidgetDataAction::class);

            foreach ($widgets as [$type, $source]) {
                $action->execute($team, $type, $source, new WidgetScope(), ['compare' => 'previous']);
            }
        };

        expect($this->queriesRunBy($compared([[WidgetType::Stat, 'ai_cost'], [WidgetType::Stat, 'ai_tokens'], [WidgetType::Stat, 'ai_error_rate']])))
            ->toBe($this->queriesRunBy($compared([[WidgetType::Stat, 'ai_cost']])))
            ->toBe(5 * count($projects));
    });

    it('charts the five costliest models and the rest as one line, over the scope\'s projects', function () {
        $sibling = Project::factory()->create(['team_id' => $this->project->team_id]);

        foreach (range(1, 7) as $i) {
            ($this->use)($i, ['model' => "model-{$i}"]);
        }

        ($this->use)(10, ['model' => 'model-1'], $sibling);
        ($this->use)(50, ['model' => 'model-x'], Project::factory()->create());

        $series = collect(($this->resolve)(WidgetType::Chart, 'ai_cost')['series'])
            ->mapWithKeys(fn (array $s): array => [$s['name'] => collect($s['points'])->sum('v')]);

        expect($series->all())->toEqual(['model-1' => 11, 'model-7' => 7, 'model-6' => 6, 'model-5' => 5, 'model-4' => 4, 'Other models' => 5]);
    });

    it('shows nothing without usage', function (WidgetType $type, string $source, string $key, mixed $empty) {
        expect(($this->resolve)($type, $source)[$key])->toBe($empty);
    })->with([
        'cost' => [WidgetType::Stat, 'ai_cost', 'value', 0.0],
        'error rate' => [WidgetType::Gauge, 'ai_error_rate', 'value', null],
        'spenders' => [WidgetType::List, 'ai_spenders', 'rows', []],
        'chart' => [WidgetType::Chart, 'ai_tokens', 'series', []],
    ]);
});

describe('Top AI spenders', function () {
    it('lists keys by name, costliest first, and never by fingerprint', function () {
        $named = AiKey::factory()->forProject($this->project)->create(['name' => 'Night jobs']);
        $bare = AiKey::factory()->forProject($this->project)->create(['name' => null, 'label' => null]);
        AiUsageHourly::factory()->forKey($named)->create(['input_tokens' => 1_000_000]);
        AiUsageHourly::factory()->forKey($bare)->create(['input_tokens' => 3_000_000]);

        $data = ($this->resolve)(WidgetType::List, 'ai_spenders');
        $shared = ($this->resolve)(WidgetType::List, 'ai_spenders', shared: true);

        expect(array_column($data['rows'], 'label'))->toBe([$bare->shortId(), 'Night jobs'])
            ->and(array_column($data['rows'], 'value'))->toBe([3.0, 1.0])
            ->and($data['unit'])->toBe('currency:EUR')
            ->and(array_column($shared['rows'], 'label'))->toBe([$bare->shortId(), $named->shortId()])
            ->and(json_encode([$data, $shared]))->not->toContain($named->fingerprint)->not->toContain($bare->fingerprint);
    });

    it('lists an owner once over every project, usage without one as No owner, numbered on a shared screen', function () {
        $sibling = Project::factory()->create(['team_id' => $this->project->team_id]);
        ($this->use)(2, ['owner_type' => 'tenant', 'owner_id' => '7']);
        ($this->use)(2, ['owner_type' => 'tenant', 'owner_id' => '7'], $sibling);
        ($this->use)(3);
        ($this->use)(1, ['owner_type' => 'tenant', 'owner_id' => '8']);

        $rows = ($this->resolve)(WidgetType::List, 'ai_spenders', ['ai_spenders' => 'owners'])['rows'];

        expect(array_column($rows, 'label'))->toBe(['tenant:7', 'No owner', 'tenant:8'])
            ->and(array_column($rows, 'value'))->toBe([4.0, 3.0, 1.0])
            ->and($rows[0]['project'])->toBeNull()
            ->and(array_column(($this->resolve)(WidgetType::List, 'ai_spenders', ['ai_spenders' => 'owners'], shared: true)['rows'], 'label'))->toBe(['Owner 1', 'No owner', 'Owner 2']);
    });
});

describe('an AI budget', function () {
    it('shows what was used of it, its share and where it is headed', function () {
        $budget = AiBudget::factory()->forProject($this->project)->costing(10)->create(['name' => 'Monthly']);
        ($this->use)(9);

        $stat = ($this->resolve)(WidgetType::Stat, 'ai_budget', ['ai_budget' => $budget->uuid]);

        expect($stat)->toMatchArray(['unit' => 'currency:EUR', 'value' => 9.0, 'state' => 'amber'])
            ->and($stat['budget'])->toMatchArray(['name' => 'Monthly', 'amount' => 10.0, 'share' => 0.9, 'period' => 'month'])
            ->and(($this->resolve)(WidgetType::Gauge, 'ai_budget', ['ai_budget' => $budget->uuid]))->toMatchArray(['value' => 0.9, 'max' => 1.0, 'state' => 'amber']);
    });

    it('shows nothing of another team\'s budget, or of one outside the widget\'s projects', function () {
        $foreign = AiBudget::factory()->forProject(Project::factory()->create())->costing(10)->create();
        $sibling = AiBudget::factory()->forProject(Project::factory()->create(['team_id' => $this->project->team_id]))->costing(10)->create();

        expect(($this->resolve)(WidgetType::Stat, 'ai_budget', ['ai_budget' => $foreign->uuid]))->toMatchArray(['value' => null])
            ->not->toHaveKey('budget')
            ->and(($this->resolve)(WidgetType::Stat, 'ai_budget', ['ai_budget' => $sibling->uuid], new WidgetScope(projects: [$this->project->uuid])))->toMatchArray(['value' => null])
            ->and(($this->resolve)(WidgetType::Stat, 'ai_budget', ['ai_budget' => $sibling->uuid])['budget'])->not->toBeNull();
    });
});

describe('the AI error rate', function () {
    it('is the share of calls that failed, of the scope or of one key', function () {
        $key = AiKey::factory()->forProject($this->project)->create();
        ($this->use)(1, ['calls' => 10, 'errors' => 1]);
        AiUsageHourly::factory()->forKey($key)->create(['calls' => 10, 'errors' => 5]);
        $foreign = AiKey::factory()->create();

        expect(($this->resolve)(WidgetType::Stat, 'ai_error_rate'))->toMatchArray(['unit' => 'ratio', 'value' => 0.3, 'state' => 'red'])
            ->and(($this->resolve)(WidgetType::Gauge, 'ai_error_rate', ['ai_key' => $key->uuid])['value'])->toBe(0.5)
            ->and(($this->resolve)(WidgetType::Gauge, 'ai_error_rate', ['ai_key' => $foreign->uuid])['value'])->toBeNull();
    });
});
