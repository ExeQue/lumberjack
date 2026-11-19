<?php

declare(strict_types=1);

use App\Domains\Ai\Http\Controllers\AiUsageController;
use App\Domains\Ai\Http\Requests\AiUsageFiltersRequest;
use App\Domains\Ai\Http\Requests\LookUpAiKeyRequest;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

covers(AiUsageController::class, AiUsageFiltersRequest::class, AiUsagePresenter::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:30:00');
    $this->project = Project::factory()->create();
    $this->user = User::factory()->inTeam($this->project->team)->create();
    AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
});

describe('the AI usage page', function () {
    it('points to the docs while the project has no AI calls', function () {
        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai/index')
                ->where('has_data', false)
                ->where('docs_url', fn (string $url): bool => str_ends_with($url, '/docs/ai'))
                ->missing('summary'));
    });

    it('hashes a key for the drivers the project\'s keys use first, then the built-in ones', function () {
        AiKey::factory()->forProject($this->project)->create(['driver' => 'my-proxy']);
        AiKey::factory()->forProject($this->project)->create(['driver' => 'openai']);
        AiKey::factory()->forProject(Project::factory()->create())->create(['driver' => 'elsewhere']);

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('lookup_drivers', fn ($drivers) => collect($drivers)->take(2)->all() === ['my-proxy', 'openai']
                && collect($drivers)->duplicates()->isEmpty()
                && ! collect($drivers)->contains('elsewhere')
                && collect($drivers)->contains('openai-compatible')
                && count($drivers) <= LookUpAiKeyRequest::MAX_HASHES));
    });

    it('compares the totals and the costliest with the period before holding as much of the hour before it as has gone by of this one', function (string $at, float $previous) {
        Carbon::setTestNow("2026-11-18 {$at}");
        // Over the last day: from 12:00 yesterday; the day before is 12:00 to 12:00, and that share of the hour before.
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-16 11:00:00', 'input_tokens' => 6_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-16 13:00:00', 'input_tokens' => 2_000_000, 'output_tokens' => 0]);
        // The current day's first hour, which the day before never counts.
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-17 12:00:00', 'input_tokens' => 4_000_000, 'output_tokens' => 0]);

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'period' => '24h']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('summary.cost', 4)
                ->where('summary.previous.cost', fn (float|int $cost): bool => abs($cost - $previous) < 0.0001)
                ->where('owners.data.0.previous_cost', fn (float|int $cost): bool => abs($cost - $previous) < 0.0001)));
    })->with([
        'just past the hour' => ['12:00:30', 2.05],
        'half past' => ['12:30:00', 5.0],
    ]);

    it('compares an actor\'s days with the days before, as much of the day before them as has gone by of today', function () {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $day = fn (string $day, int $millions) => AiUsageDaily::factory()->forProject($this->project)->byActor('user', 1)->create(['day' => $day, 'input_tokens' => $millions * 1_000_000, 'output_tokens' => 0]);
        $day('2026-11-15', 2);
        $day('2026-11-16', 4);
        // The current period's first day, which the days before never count.
        $day('2026-11-17', 8);

        $this->actingAs($this->user)
            ->get(route('ai.actors.show', ['project' => $this->project, 'type' => 'user', 'id' => '1', 'period' => '24h']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('summary.cost', 8)
                ->where('summary.previous.cost', 5)));

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'period' => '24h']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('actors.data.0.cost', 8)
                ->where('actors.data.0.previous_cost', 5)));
    });

    it('compares an actor\'s last hour with as many whole days before as it spans, and the share of today gone by', function (string $at, int $cost, float $previous) {
        Carbon::setTestNow("2026-11-18 {$at}");
        $day = fn (string $day, int $millions) => AiUsageDaily::factory()->forProject($this->project)->byActor('user', 1)->create(['day' => $day, 'input_tokens' => $millions * 1_000_000, 'output_tokens' => 0]);
        $day('2026-11-15', 2);
        $day('2026-11-16', 4);
        $day('2026-11-17', 8);
        $day('2026-11-18', 16);

        $this->actingAs($this->user)
            ->get(route('ai.actors.show', ['project' => $this->project, 'type' => 'user', 'id' => '1', 'period' => '1h']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('summary.cost', $cost)
                ->where('summary.previous.cost', fn (float|int $value): bool => abs($value - $previous) < 0.0001)));

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'period' => '1h']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('actors.data.0.cost', $cost)
                ->where('actors.data.0.previous_cost', fn (float|int $value): bool => abs($value - $previous) < 0.0001)));
    })->with([
        // The hour reaches back into yesterday: yesterday and today, against the day before and its share of the one before that.
        'half past midnight' => ['00:30:00', 24, 4 + 2 * 0.5 / 24],
        'half past one' => ['01:30:00', 16, 8 * 1.5 / 24],
        'three o\'clock' => ['03:00:00', 16, 8 * 3 / 24],
    ]);

    it('loads the totals, the chart and the costliest keys, owners and actors after the page', function () {
        $cheap = AiKey::factory()->forProject($this->project)->labeled('cheap')->create();
        $costly = AiKey::factory()->forProject($this->project)->labeled('costly')->ownedBy('tenant', 7)->create();
        AiUsageHourly::factory()->forKey($cheap)->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forKey($costly)->create(['input_tokens' => 3_000_000, 'output_tokens' => 0]);
        // The period before: the costly key cost a third of what it does now.
        AiUsageHourly::factory()->forKey($costly)->create(['hour' => now()->subDays(40)->startOfHour(), 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageDaily::factory()->forKey($costly)->byActor('user', 1)->create(['input_tokens' => 3_000_000, 'output_tokens' => 0]);
        AiUsageDaily::factory()->forKey($cheap)->byActor('user', 2)->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('has_data', true)
                ->where('filters.period', '30d')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('summary.cost_ours', 1)
                    ->where('summary.cost_customer', 3)
                    ->where('summary.previous.cost', 1)
                    ->where('chart.cost.0.name', 'claude-sonnet-4-5')
                    ->where('keys.total', 2)
                    ->where('keys.data.0.key.label', 'costly')
                    ->where('keys.data.0.previous_cost', 1)
                    ->where('owners.data.0.reference', ['type' => 'tenant', 'id' => '7'])
                    ->where('owners.data.1.reference', null)
                    ->where('actors.data.0.reference', ['type' => 'user', 'id' => '1'])
                    ->where('actors.data.0.cost_customer', 3)));
    });

    it('searches and pages each table on its own', function () {
        AiKey::factory()->forProject($this->project)->count(12)->create()
            ->each(fn (AiKey $key) => AiUsageHourly::factory()->forKey($key)->create());
        $prod = AiKey::factory()->forProject($this->project)->labeled('prod-primary')->create();
        AiUsageHourly::factory()->forKey($prod)->create();

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'keys_search' => 'PROD', 'actors_page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.keys_search', 'PROD')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('keys.total', 1)
                    ->where('keys.data.0.key.uuid', $prod->uuid)));

        // By the name given in Lumberjack too.
        $named = AiKey::factory()->forProject($this->project)->create(['name' => 'Checkout helper']);
        AiUsageHourly::factory()->forKey($named)->create();

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'keys_search' => 'checkout']))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('keys.total', 1)
                ->where('keys.data.0.key.uuid', $named->uuid)));

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'keys_page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('keys.total', 14)
                ->where('keys.current_page', 2)
                ->has('keys.data', 4)));
    });

    it('turns away a page number too large to count to', function () {
        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'keys_page' => '9223372036854775807']))
            ->assertSessionHasErrors('keys_page');
    });

    it('shows the last page for a page past the end', function () {
        AiKey::factory()->forProject($this->project)->count(12)->create()
            ->each(fn (AiKey $key) => AiUsageHourly::factory()->forKey($key)->create());

        $this->actingAs($this->user)
            ->get(route('ai.index', ['project' => $this->project, 'keys_page' => 9]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('keys.total', 12)
                ->where('keys.current_page', 2)
                ->has('keys.data', 2)));
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->get(route('ai.index', ['project' => $this->project]))
            ->assertForbidden();
    });
});

describe('one owner or actor', function () {
    it('shows an owner\'s usage per key', function () {
        $key = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create();
        AiUsageHourly::factory()->forKey($key)->create(['input_tokens' => 2_000_000, 'output_tokens' => 0]);
        // A call with the tenant's key that names no owner is still the tenant's.
        AiUsageHourly::factory()->forKey($key)->ownedBy('', '')->create(['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forProject($this->project)->ownedBy('tenant', 8)->create();

        $this->actingAs($this->user)
            ->get(route('ai.owners.show', ['project' => $this->project, 'type' => 'tenant', 'id' => '7']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai/reference')
                ->where('kind', 'owner')
                ->where('reference', ['type' => 'tenant', 'id' => '7'])
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('summary.calls', 2)
                    ->where('summary.cost_customer', 3)
                    ->has('by_key', 1)
                    ->where('by_key.0.key.uuid', $key->uuid)));
    });

    it('opens an actor whose type and id have slashes in them', function () {
        AiUsageDaily::factory()->forProject($this->project)->byActor('jobs/service', 'billing/worker-2')->create();

        $this->actingAs($this->user)
            ->get(route('ai.actors.show', ['project' => $this->project, 'type' => 'jobs/service', 'id' => 'billing/worker-2']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reference', ['type' => 'jobs/service', 'id' => 'billing/worker-2'])
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('summary.calls', 1)));
    });

    it('shows an actor\'s usage, a class name for a type included', function () {
        AiUsageDaily::factory()->forProject($this->project)->byActor('App\Models\User', 17)->count(2)->sequence(['model' => 'a'], ['model' => 'b'])->create();
        AiUsageDaily::factory()->forProject($this->project)->byActor('App\Models\User', 18)->create();

        $this->actingAs($this->user)
            ->get(route('ai.actors.show', ['project' => $this->project, 'type' => 'App\Models\User', 'id' => '17']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kind', 'actor')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('summary.calls', 2)
                    ->has('chart.tokens', 2)));
    });
});
