<?php

declare(strict_types=1);

use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

covers(AiBudgetQuery::class, AiBudgetUsage::class, AiBudgetPeriod::class, AiBudget::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:30:00');
    $this->project = Project::factory()->create();
    AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
    $this->measure = fn (AiBudget $budget): AiBudgetUsage => app(AiBudgetQuery::class)->measure($budget);
});

describe('what a budget counts', function () {
    beforeEach(function () {
        $this->key = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create();
        // A call naming no owner on the tenant's key, a keyless call for another tenant, and an actor's day.
        AiUsageHourly::factory()->forProject($this->project)->create(['fingerprint' => $this->key->fingerprint, 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forProject($this->project)->ownedBy('tenant', 8)->create(['input_tokens' => 2_000_000, 'output_tokens' => 500_000]);
        AiUsageDaily::factory()->forProject($this->project)->byActor('user', 1)->create(['input_tokens' => 4_000_000, 'output_tokens' => 0]);
    });

    it('sums the cost or the tokens of what it counts', function (Closure $state, float $cost, int $tokens) {
        // A day, from the hourly totals; an actor's from the daily ones.
        $budget = $state(AiBudget::factory()->forProject($this->project)->daily(), $this->key);

        expect(($this->measure)($budget->costing(100)->create())->spent)->toEqualWithDelta($cost, 0.000001)
            ->and(($this->measure)($budget->tokens(10_000_000)->create())->spent)->toBe((float) $tokens);
    })->with([
        'the whole project, every payer' => [fn ($f) => $f, 3.0, 3_500_000],
        'one key' => [fn ($f, AiKey $key) => $f->forKey($key), 1.0, 1_000_000],
        'a key owner, by the key it owns too' => [fn ($f) => $f->forOwner('tenant', 7), 1.0, 1_000_000],
        'another key owner, without a key' => [fn ($f) => $f->forOwner('tenant', 8), 2.0, 2_500_000],
        'an actor, from the daily totals' => [fn ($f) => $f->forActor('user', 1), 4.0, 4_000_000],
    ]);

    it('counts only the usage of the payers it is for', function (AiBudgetPayer $payer, float $cost, int $tokens) {
        $budget = AiBudget::factory()->forProject($this->project)->daily()->paidBy($payer);

        // The tenant's key and the keyless call for tenant 8 are customers'; the actor's day is not in the hourly totals.
        expect(($this->measure)($budget->costing(100)->create())->spent)->toEqualWithDelta($cost, 0.000001)
            ->and(($this->measure)($budget->tokens(10_000_000)->create())->spent)->toBe((float) $tokens);
    })->with([
        'anyone' => [AiBudgetPayer::All, 3.0, 3_500_000],
        'customers' => [AiBudgetPayer::Customer, 3.0, 3_500_000],
        'us' => [AiBudgetPayer::Us, 0.0, 0],
    ]);

    it('knows who pays for tokens no price applies to', function () {
        AiUsageHourly::factory()->forProject($this->project)->create(['model' => 'unpriced', 'input_tokens' => 700, 'output_tokens' => 0]);

        expect(($this->measure)(AiBudget::factory()->forProject($this->project)->daily()->paidBy(AiBudgetPayer::Us)->tokens(1000)->create())->spent)->toBe(700.0);
    });

    it('sums the owners it counts once each, with their keys, and no others', function () {
        $twelve = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 12)->create();
        $nine = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 9)->create();
        // Names tenant 12 on tenant 12's key: matches both ways, counted once.
        AiUsageHourly::factory()->forKey($twelve)->ownedBy('tenant', 12)->create(['input_tokens' => 3_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forKey($nine)->create(['input_tokens' => 7_000_000, 'output_tokens' => 0]);

        // Tenant 7's key (1), tenant 8's keyless call (2.5), tenant 12 (3); not tenant 9.
        expect(($this->measure)(AiBudget::factory()->forProject($this->project)->daily()->forOwner('tenant', 7, ['tenant', 8], ['tenant', 12])->tokens(100_000_000)->create())->spent)->toBe(6_500_000.0);
    });

    it('sums the actors it counts, and no others', function () {
        AiUsageDaily::factory()->forProject($this->project)->byActor('user', 2)->create(['input_tokens' => 2_000_000, 'output_tokens' => 0]);
        AiUsageDaily::factory()->forProject($this->project)->byActor('user', 3)->create(['input_tokens' => 9_000_000, 'output_tokens' => 0]);

        expect(($this->measure)(AiBudget::factory()->forProject($this->project)->daily()->forActor('user', 1, ['user', 2])->tokens(100_000_000)->create())->spent)->toBe(6_000_000.0);
    });

    it('sums the keys it counts, and no others', function () {
        [$second, $third] = AiKey::factory()->forProject($this->project)->count(2)->create();
        AiUsageHourly::factory()->forKey($second)->create(['input_tokens' => 3_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forKey($third)->create(['input_tokens' => 7_000_000, 'output_tokens' => 0]);

        expect(($this->measure)(AiBudget::factory()->forKey($this->key, $second)->create())->spent)->toEqualWithDelta(4.0, 0.000001);
    });
});

describe('the period', function () {
    it('counts from the start of the day or month in the app\'s time zone, and up to its end', function (Closure $state, string $before, string $from) {
        $usage = fn (string $hour) => AiUsageHourly::factory()->forProject($this->project)->create(['hour' => $hour, 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        $usage($before);
        $usage($from);

        $measured = ($this->measure)($state(AiBudget::factory()->forProject($this->project))->create());

        expect($measured->spent)->toEqualWithDelta(1.0, 0.000001)
            ->and($measured->start->toDateString())->toBe(substr($from, 0, 10));
    })->with([
        'a day' => [fn ($f) => $f->daily(), '2026-11-17 23:00:00', '2026-11-18 00:00:00'],
        'a month' => [fn ($f) => $f, '2026-10-31 23:00:00', '2026-11-01 00:00:00'],
    ]);

    it('measures a period that ended only to its end', function () {
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-17 23:00:00', 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-18 00:00:00', 'input_tokens' => 5_000_000, 'output_tokens' => 0]);

        $measured = app(AiBudgetQuery::class)->measure(AiBudget::factory()->forProject($this->project)->daily()->create(), CarbonImmutable::parse('2026-11-17 23:59:59'));

        expect($measured->spent)->toEqualWithDelta(1.0, 0.000001);
    });

    it('projects the pace so far to the end of the period, once enough of it went by', function (string $now, float $projected) {
        Carbon::setTestNow($now);
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-01 08:00:00', 'input_tokens' => 10_000_000, 'output_tokens' => 0]);

        $measured = ($this->measure)(AiBudget::factory()->forProject($this->project)->costing(15)->create());

        expect($measured->projected())->toEqualWithDelta($projected, 0.0001)
            ->and($measured->projectsOverrun())->toBe($projected > 15);
    })->with([
        'half of November gone' => ['2026-11-16 00:00:00', 20.0],
        'too early to say' => ['2026-11-02 00:00:00', 10.0],
    ]);
});

describe('the budgets that count something', function () {
    it('stores a target as a key or as a type and id, never both or neither', function (array $target) {
        $budget = AiBudget::factory()->forProject($this->project)->create();

        expect(fn () => $budget->targets()->create($target))->toThrow(QueryException::class, 'ai_budget_targets_one_kind');
    })->with([
        'both' => [['fingerprint' => 'f', 'ref_type' => 'tenant', 'ref_id' => '7']],
        'neither' => [['fingerprint' => null, 'ref_type' => null, 'ref_id' => null]],
        'a type without an id' => [['fingerprint' => null, 'ref_type' => 'tenant', 'ref_id' => null]],
        'an id without a type' => [['fingerprint' => null, 'ref_type' => null, 'ref_id' => '7']],
    ]);

    it('finds a key budget from each of its keys, and not from another', function () {
        [$first, $second, $third] = AiKey::factory()->forProject($this->project)->count(3)->create();
        $budget = AiBudget::factory()->forKey($first, $second)->create();
        AiBudget::factory()->forProject($this->project)->forOwner('tenant', $first->fingerprint)->create();
        $covering = fn (AiKey $key): array => app(AiBudgetQuery::class)->covering($this->project, AiBudgetScope::Key, null, $key->fingerprint)->pluck('id')->all();

        expect($covering($first))->toBe([$budget->id])
            ->and($covering($second))->toBe([$budget->id])
            ->and($covering($third))->toBe([]);
    });

    it('finds an owner or actor budget from each of them', function (AiBudgetScope $scope, Closure $state) {
        $budget = $state(AiBudget::factory()->forProject($this->project))->create();
        $covering = fn (string $id): array => app(AiBudgetQuery::class)->covering($this->project, $scope, 'tenant', $id)->pluck('id')->all();

        expect($covering('7'))->toBe([$budget->id])
            ->and($covering('8'))->toBe([$budget->id])
            ->and($covering('9'))->toBe([]);
    })->with([
        'owners' => [AiBudgetScope::Owner, fn ($f) => $f->forOwner('tenant', 7, ['tenant', 8])],
        'actors' => [AiBudgetScope::Actor, fn ($f) => $f->forActor('tenant', 7, ['tenant', 8])],
    ]);
});
