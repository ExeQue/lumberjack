<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;

covers(AiCostQuery::class, AiPrice::class, AiKey::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:30:00');
    $this->project = Project::factory()->create();
    $this->key = AiKey::factory()->forProject($this->project)->create();
    $this->query = app(AiCostQuery::class);
    $this->since = now()->subDays(30)->startOfHour();
    $this->total = fn (string $table = AiCostQuery::HOURLY): array => $this->query->total($this->project, $table, $this->since);
});

describe('what tokens cost', function () {
    it('prices input less the cached tokens, and cache reads and writes at their own price', function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(3, 15, 0.3, 3.75)->create();
        AiUsageHourly::factory()->forKey($this->key)->create([
            'input_tokens' => 1_000_000,
            'cache_read_tokens' => 400_000,
            'cache_write_tokens' => 100_000,
            'output_tokens' => 200_000,
        ]);

        // 500k uncached * 3 + 200k * 15 + 400k * 0.3 + 100k * 3.75, per million.
        expect(($this->total)()['cost'])->toEqualWithDelta(4.995, 0.000001)
            ->and(($this->total)()['tokens'])->toBe(1_200_000);
    });

    it('prices cached tokens as input when the price has no cache price', function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(3, 15)->create();
        AiUsageHourly::factory()->forKey($this->key)->create(['input_tokens' => 1_000_000, 'cache_read_tokens' => 600_000, 'output_tokens' => 0]);

        expect(($this->total)()['cost'])->toEqualWithDelta(3.0, 0.000001);
    });

    it('never prices fewer than no uncached input tokens', function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(3, 15, 0.3)->create();
        // An app that reported input without its cached tokens.
        AiUsageHourly::factory()->forKey($this->key)->create(['input_tokens' => 100_000, 'cache_read_tokens' => 1_000_000, 'output_tokens' => 0]);

        expect(($this->total)()['cost'])->toEqualWithDelta(0.3, 0.000001);
    });

    it('bills reasoning as output, or at its own price when it has one', function (?float $reasoning, float $expected) {
        AiPrice::factory()->forTeamOf($this->project)->costing(3, 15, reasoning: $reasoning)->create();
        AiUsageHourly::factory()->forKey($this->key)->create(['input_tokens' => 0, 'output_tokens' => 1_000_000, 'reasoning_tokens' => 400_000]);

        expect(($this->total)()['cost'])->toEqualWithDelta($expected, 0.000001);
    })->with([
        'without a reasoning price' => [null, 15.0],
        'with a reasoning price' => [30.0, 600_000 * 15 / 1e6 + 400_000 * 30 / 1e6],
    ]);

    it('counts the tokens no price applies to, at no cost', function () {
        AiPrice::factory()->forTeamOf($this->project)->model('gpt-*')->create();
        AiUsageHourly::factory()->forKey($this->key)->create(['input_tokens' => 1000, 'output_tokens' => 200]);

        expect(($this->total)())->toMatchArray(['cost' => 0.0, 'unpriced_tokens' => 1200, 'tokens' => 1200]);
    });
});

describe('who pays', function () {
    beforeEach(function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
        $usage = fn (?AiKey $key) => AiUsageHourly::factory()->forProject($this->project)->create(['fingerprint' => $key?->fingerprint ?? '', 'input_tokens' => 1_000_000, 'output_tokens' => 0]);

        $usage(AiKey::factory()->forProject($this->project)->create(['payer' => AiPayer::Customer]));
        $usage(AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create());
        $usage(AiKey::factory()->forProject($this->project)->ownedBy('tenant', 8)->create(['payer' => AiPayer::Us]));
        $usage($this->key);
        $usage(null);
    });

    it('splits cost by the key\'s payer, an owned key\'s customer, else the project\'s default', function () {
        expect(($this->total)())->toMatchArray(['cost_customer' => 2.0, 'cost_ours' => 3.0, 'cost' => 5.0]);
    });

    it('makes the customer pay for a call without a key made for an owner, by the day and by the hour', function () {
        AiUsageDaily::factory()->forProject($this->project)->ownedBy('tenant', 9)->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forProject($this->project)->ownedBy('tenant', 9)->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        expect($this->query->total($this->project, AiCostQuery::DAILY, $this->since))->toMatchArray(['cost_customer' => 1.0, 'cost_ours' => 0.0])
            ->and(($this->total)())->toMatchArray(['cost_customer' => 3.0, 'cost_ours' => 3.0]);
    });

    it('prices a call with an owned key at the owner\'s price, though the call names no owner', function () {
        AiPrice::factory()->forOwner($this->project, 'tenant', '70')->costing(5, 0)->create();
        $key = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 70)->create();
        AiUsageHourly::factory()->forProject($this->project)->create(['fingerprint' => $key->fingerprint, 'input_tokens' => 1_000_000, 'output_tokens' => 0]);

        AiUsageDaily::factory()->forProject($this->project)->create(['fingerprint' => $key->fingerprint, 'input_tokens' => 1_000_000, 'output_tokens' => 0]);

        // The 5 at 1 each from above, and 5 for this one; by the day too.
        expect(($this->total)())->toMatchArray(['cost' => 10.0, 'cost_customer' => 7.0])
            ->and($this->query->total($this->project, AiCostQuery::DAILY, $this->since))->toMatchArray(['cost' => 5.0, 'cost_customer' => 5.0])
            ->and($this->query->top($this->project, $this->since, null, null, 'owner', 'tenant:70')['rows'][0])->toMatchArray(['owner_type' => 'tenant', 'owner_id' => '70', 'cost' => 5.0]);
    });

    it('prices a call without a key at its owner\'s price, by the hour too', function () {
        AiPrice::factory()->forOwner($this->project, 'tenant', '9')->costing(5, 0)->create();
        AiUsageHourly::factory()->forProject($this->project)->ownedBy('tenant', 9)->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        expect($this->query->total($this->project, AiCostQuery::HOURLY, $this->since, only: ['owner' => [['type' => 'tenant', 'id' => '9']]]))->toMatchArray(['cost_customer' => 5.0, 'cost' => 5.0]);
    });

    it('follows the project\'s default for keys that do not say', function () {
        $this->project->update(['ai_default_payer' => AiPayer::Customer]);

        expect(($this->total)())->toMatchArray(['cost_customer' => 4.0, 'cost_ours' => 1.0]);
    });
});

describe('a price change in the period', function () {
    beforeEach(function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
        AiPrice::factory()->forTeamOf($this->project)->validFrom('2026-11-18 06:00:00')->costing(2, 0)->create();
    });

    it('prices each hour with the price of that hour', function () {
        AiUsageHourly::factory()->forKey($this->key)->create(['hour' => '2026-11-18 05:00:00', 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forKey($this->key)->create(['hour' => '2026-11-18 06:00:00', 'input_tokens' => 1_000_000, 'output_tokens' => 0]);

        expect(($this->total)()['cost'])->toEqualWithDelta(3.0, 0.000001)
            ->and(array_column($this->query->get($this->project, AiCostQuery::HOURLY, $this->since, by: ['bucket'], bucketSeconds: 3600), 'cost', 'bucket'))
            ->toEqual([strtotime('2026-11-18 05:00:00 UTC') => 1.0, strtotime('2026-11-18 06:00:00 UTC') => 2.0]);
    });

    it('prices a day with the price at its start', function () {
        AiUsageDaily::factory()->forKey($this->key)->create(['day' => '2026-11-18', 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageDaily::factory()->forKey($this->key)->create(['day' => '2026-11-17', 'input_tokens' => 1_000_000, 'output_tokens' => 0]);

        expect(($this->total)(AiCostQuery::DAILY)['cost'])->toEqualWithDelta(2.0, 0.000001);
    });
});

describe('summing by', function () {
    it('sums the daily totals by actor, for one owner', function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
        $owned = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create();
        $daily = AiUsageDaily::factory()->forKey($owned)->state(['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        $daily->byActor('user', 1)->count(2)->sequence(['day' => '2026-11-17'], ['day' => '2026-11-18'])->create();
        $daily->byActor('user', 2)->create();
        AiUsageDaily::factory()->forKey($this->key)->byActor('user', 1)->create();

        $rows = $this->query->get($this->project, AiCostQuery::DAILY, $this->since, by: ['actor'], only: ['owner' => [['type' => 'tenant', 'id' => '7']]]);

        expect(collect($rows)->mapWithKeys(fn (array $row): array => [$row['actor_type'].':'.$row['actor_id'] => [$row['calls'], $row['cost_customer']]])->all())
            ->toEqual(['user:1' => [2, 2.0], 'user:2' => [1, 1.0]]);
    });
});

describe('the costliest', function () {
    it('counts a call under the owner it names over its key\'s owner, and one naming none under the key\'s', function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
        $key = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create();
        $usage = AiUsageHourly::factory()->forProject($this->project)->state(['fingerprint' => $key->fingerprint, 'output_tokens' => 0]);
        $usage->ownedBy('tenant', 9)->create(['input_tokens' => 2_000_000]);
        $usage->create(['input_tokens' => 1_000_000, 'model' => 'claude-opus-4-5']);

        $owners = $this->query->top($this->project, $this->since, null, null, 'owner')['rows'];
        $total = fn (string $id): int => $this->query->total($this->project, AiCostQuery::HOURLY, $this->since, only: ['owner' => [['type' => 'tenant', 'id' => $id]]])['tokens'];

        expect(array_map(fn (array $row): array => [$row['owner_id'], $row['tokens']], $owners))->toBe([['9', 2_000_000], ['7', 1_000_000]])
            ->and($total('7'))->toBe(1_000_000)
            ->and($total('9'))->toBe(2_000_000);
    });

    it('sorts, searches and pages owners by cost in the database, each with its cost', function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
        // An expensive model on few tokens, so cost and tokens disagree on the order.
        AiPrice::factory()->forTeamOf($this->project)->model('claude-opus-4-5')->costing(100, 0)->create();
        $hourly = AiUsageHourly::factory()->forProject($this->project)->state(['output_tokens' => 0]);
        $hourly->ownedBy('tenant', 1)->create(['input_tokens' => 5_000_000]);
        $hourly->ownedBy('tenant', 2)->create(['input_tokens' => 100_000, 'model' => 'claude-opus-4-5']);
        $hourly->ownedBy('tenant', 3)->create(['input_tokens' => 1_000_000]);
        $hourly->ownedBy('team', 4)->create(['input_tokens' => 2_000_000]);

        $page = fn (int $offset, string $search = '') => $this->query->top($this->project, $this->since, null, null, 'owner', $search, 2, $offset);

        expect($page(0))->total->toBe(4)
            ->and(array_map(fn (array $row): array => [$row['owner_id'], $row['cost']], $page(0)['rows']))->toEqual([['2', 10.0], ['1', 5.0]])
            ->and(array_column($page(2)['rows'], 'owner_id'))->toBe(['4', '3'])
            ->and($page(0, 'TENANT:3'))->total->toBe(1);
    });
});

describe('many keys and models', function () {
    it('prices more combinations than Postgres takes bindings', function () {
        AiPrice::factory()->forTeamOf($this->project)->model('*')->costing(1, 0)->create();
        $hour = now()->startOfHour()->toDateTimeString();
        $rows = array_map(fn (int $i): array => AiUsageHourly::factory()->forProject($this->project)->raw([
            'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
            'hour' => $hour,
            'input_tokens' => 1000,
            'output_tokens' => 0,
        ]), range(1, 7000));

        foreach (array_chunk($rows, 1000) as $chunk) {
            AiUsageHourly::query()->insert($chunk);
        }

        expect(($this->total)())->toMatchArray(['calls' => 7000 * $rows[0]['calls'], 'cost' => 7.0, 'unpriced_tokens' => 0]);
    });
});
