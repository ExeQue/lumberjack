<?php

declare(strict_types=1);

use App\Domains\Ai\Actions\DeleteAiBudgetAction;
use App\Domains\Ai\Actions\SaveAiBudgetAction;
use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Http\Controllers\AiBudgetController;
use App\Domains\Ai\Http\Requests\SaveAiBudgetRequest;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiBudgetTarget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Policies\AiBudgetPolicy;
use App\Domains\Ai\Presenters\AiBudgetPresenter;
use App\Domains\Alerts\Models\AiBudgetState;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

covers(AiBudgetController::class, SaveAiBudgetRequest::class, SaveAiBudgetAction::class, DeleteAiBudgetAction::class, AiBudgetPolicy::class, AiBudgetPresenter::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 00:00:00');
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->key = AiKey::factory()->forProject($this->project)->labeled('prod')->ownedBy('tenant', 7)->create();
    AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
    $this->valid = ['name' => 'Monthly', 'scope' => 'project', 'period' => 'month', 'basis' => 'cost', 'amount' => '25.5'];
});

describe('the budgets page', function () {
    it('lists the budgets with what they used and will at this pace', function () {
        AiBudget::factory()->forKey($this->key)->costing(10)->create(['name' => null]);
        AiUsageHourly::factory()->forKey($this->key)->create(['hour' => '2026-11-02 10:00:00', 'input_tokens' => 4_000_000, 'output_tokens' => 0]);

        $this->actingAs($this->member)
            ->get(route('ai.budgets.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai/budgets')
                ->missing('keys')
                ->where('can_update', false)
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->has('budgets', 1)
                    ->where('budgets.0.title', 'Key prod')
                    ->where('budgets.0.keys.0.value', $this->key->uuid)
                    ->where('budgets.0.usage.spent', 4)
                    ->where('budgets.0.usage.percent', 40)
                    ->where('budgets.0.usage.projected', 8)));
    });

    it('shows a key\'s budgets on its page', function () {
        AiBudget::factory()->forKey($this->key)->create();
        AiBudget::factory()->forProject($this->project)->create();

        $this->actingAs($this->member)
            ->get(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $this->key->uuid]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('budgets', 1)));
    });

    it('shows a budget on each of its owners\' and actors\' pages', function (string $route, Closure $factory) {
        $factory(AiBudget::factory()->forProject($this->project))->create();

        foreach (['7' => 1, '8' => 1, '9' => 0] as $id => $count) {
            $this->actingAs($this->member)
                ->get(route($route, ['project' => $this->project, 'type' => 'tenant', 'id' => $id]))
                ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('budgets', $count)));
        }
    })->with([
        'owners' => ['ai.owners.show', fn ($f) => $f->forOwner('tenant', 7, ['tenant', 8])],
        'actors' => ['ai.actors.show', fn ($f) => $f->forActor('tenant', 7, ['tenant', 8])],
    ]);
});

describe('setting budgets', function () {
    it('adds one for the project, keys, owners or actors', function (array $values, AiBudgetScope $scope, array $references) {
        $this->actingAs($this->admin)
            ->post(route('ai.budgets.store', ['project' => $this->project]), [...$this->valid, 'keys' => [$this->key->uuid], 'owners' => [['type' => 'x', 'id' => '1']], ...$values])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(AiBudget::query()->sole())
            ->scope->toBe($scope)
            ->amount->toBe(25.5)
            ->period->toBe(AiBudgetPeriod::Month)
            ->basis->toBe(AiBudgetBasis::Cost)
            ->fingerprints()->toBe($scope === AiBudgetScope::Key ? [$this->key->fingerprint] : [])
            ->references()->toBe($references);
    })->with([
        'the project' => [['scope' => 'project'], AiBudgetScope::Project, []],
        'a key, by its fingerprint' => [['scope' => 'key'], AiBudgetScope::Key, []],
        'two owners' => [['scope' => 'owner', 'owners' => [['type' => 'tenant', 'id' => '8'], ['type' => 'tenant', 'id' => '7']]], AiBudgetScope::Owner, [['type' => 'tenant', 'id' => '7'], ['type' => 'tenant', 'id' => '8']]],
        'an actor with a slash' => [['scope' => 'actor', 'actors' => [['type' => 'App\Models\User', 'id' => 'a/1']]], AiBudgetScope::Actor, [['type' => 'App\Models\User', 'id' => 'a/1']]],
    ]);

    it('rejects what does not make a budget', function (array $values, string $error) {
        $this->actingAs($this->admin)
            ->post(route('ai.budgets.store', ['project' => $this->project]), [...$this->valid, ...$values])
            ->assertSessionHasErrors($error);
    })->with([
        'no amount' => [['amount' => ''], 'amount'],
        'nothing to spend' => [['amount' => '0'], 'amount'],
        'part of a token' => [['basis' => 'tokens', 'amount' => '10.5'], 'amount'],
        'a week' => [['period' => 'week'], 'period'],
        'no keys' => [['scope' => 'key', 'keys' => []], 'keys'],
        'a key that is not one' => [['scope' => 'key', 'keys' => ['nope']], 'keys.0'],
        'no owners' => [['scope' => 'owner', 'owners' => []], 'owners'],
        'an owner without an id' => [['scope' => 'owner', 'owners' => [['type' => 'tenant']]], 'owners.0.id'],
        'an actor id too long' => [['scope' => 'actor', 'actors' => [['type' => 'user', 'id' => str_repeat('1', 65)]]], 'actors.0.id'],
        'an owner twice' => [['scope' => 'owner', 'owners' => [['type' => 'tenant', 'id' => '7'], ['type' => 'tenant', 'id' => '7']]], 'owners.1'],
        'an owner type that is a list' => [['scope' => 'owner', 'owners' => [['type' => ['a'], 'id' => '7'], ['type' => ['a'], 'id' => '7']]], 'owners.0.type'],
    ]);

    it('counts what we pay for the project or a key, and everything for an owner or actor, unless told', function (array $values, AiBudgetPayer $payer) {
        $this->actingAs($this->admin)->post(route('ai.budgets.store', ['project' => $this->project]), [...$this->valid, 'keys' => [$this->key->uuid], ...$values])->assertSessionHasNoErrors();

        expect(AiBudget::query()->sole()->payer)->toBe($payer);
    })->with([
        'the project' => [['scope' => 'project'], AiBudgetPayer::Us],
        'a key' => [['scope' => 'key'], AiBudgetPayer::Us],
        'an owner' => [['scope' => 'owner', 'owners' => [['type' => 'tenant', 'id' => '7']]], AiBudgetPayer::All],
        'an actor' => [['scope' => 'actor', 'actors' => [['type' => 'user', 'id' => '1']]], AiBudgetPayer::All],
        'the project, for customers' => [['scope' => 'project', 'payer' => 'customer'], AiBudgetPayer::Customer],
    ]);

    it('rejects a key of another project, and one twice', function (Closure $keys, string $error) {
        $this->actingAs($this->admin)
            ->post(route('ai.budgets.store', ['project' => $this->project]), [...$this->valid, 'scope' => 'key', 'keys' => $keys($this->key)])
            ->assertSessionHasErrors($error);
    })->with([
        'another project\'s' => [fn (AiKey $key) => [$key->uuid, AiKey::factory()->create()->uuid], 'keys.1'],
        'twice' => [fn (AiKey $key) => [$key->uuid, $key->uuid], 'keys.1'],
    ]);

    it('keeps a key the project forgot until it is taken out, and starts its alerts over when its keys change', function () {
        $other = AiKey::factory()->forProject($this->project)->create();
        $budget = AiBudget::factory()->forKey($this->key, $other)->create();
        AiBudgetState::query()->create(['ai_budget_id' => $budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);
        $this->key->delete();
        $forgotten = AiBudgetTarget::forgotten($this->key->fingerprint);
        $update = fn (array $keys) => $this->actingAs($this->admin)->put(route('ai.budgets.update', ['project' => $this->project, 'aiBudget' => $budget->uuid]), [...$this->valid, 'scope' => 'key', 'payer' => 'all', 'keys' => $keys]);

        $update([$forgotten, $other->uuid])->assertSessionHasNoErrors();

        expect($budget->refresh()->fingerprints())->toBe(collect([$this->key->fingerprint, $other->fingerprint])->sort()->values()->all())
            ->and($budget->states()->count())->toBe(1);

        $update([$other->uuid])->assertSessionHasNoErrors();

        expect($budget->refresh()->fingerprints())->toBe([$other->fingerprint])
            ->and($budget->states()->count())->toBe(0);

        // Taken out, it is no longer the budget's to name.
        $update([$forgotten])->assertSessionHasErrors('keys.0');
    });

    it('keeps a type holding a colon whole, and tells pairs apart that join to the same text', function () {
        $owners = [['type' => 'urn:tenant', 'id' => '7'], ['type' => 'a', 'id' => 'b:c'], ['type' => 'a:b', 'id' => 'c']];

        $this->actingAs($this->admin)
            ->post(route('ai.budgets.store', ['project' => $this->project]), [...$this->valid, 'scope' => 'owner', 'owners' => $owners, 'actors' => [['type' => 'x', 'id' => '1'], ['type' => 'x', 'id' => '1']]])
            ->assertSessionHasNoErrors();

        expect(AiBudget::query()->sole()->references())->toBe([['type' => 'a', 'id' => 'b:c'], ['type' => 'a:b', 'id' => 'c'], ['type' => 'urn:tenant', 'id' => '7']]);
    });

    it('starts its alerts over when its owners change, and not when they are given again in another order', function () {
        $budget = AiBudget::factory()->forProject($this->project)->forOwner('tenant', 7, ['tenant', 8])->create();
        AiBudgetState::query()->create(['ai_budget_id' => $budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);
        $update = fn (array $owners) => $this->actingAs($this->admin)->put(route('ai.budgets.update', ['project' => $this->project, 'aiBudget' => $budget->uuid]), [...$this->valid, 'scope' => 'owner', 'payer' => 'all', 'amount' => '100', 'owners' => $owners])->assertSessionHasNoErrors();

        $update([['type' => 'tenant', 'id' => '8'], ['type' => 'tenant', 'id' => '7']]);

        expect($budget->states()->count())->toBe(1);

        $update([['type' => 'tenant', 'id' => '8']]);

        expect($budget->states()->count())->toBe(0)
            ->and($budget->refresh()->references())->toBe([['type' => 'tenant', 'id' => '8']]);
    });

    it('starts its alerts over when it counts something else', function (array $values) {
        $budget = AiBudget::factory()->forProject($this->project)->create();
        AiBudgetState::query()->create(['ai_budget_id' => $budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);

        $this->actingAs($this->admin)->put(route('ai.budgets.update', ['project' => $this->project, 'aiBudget' => $budget->uuid]), [...$this->valid, ...$values])->assertSessionHasNoErrors();

        expect($budget->states()->count())->toBe(0);
    })->with([
        'another period' => [['period' => 'day']],
        'another payer' => [['payer' => 'customer']],
    ]);

    it('keeps of a new amount\'s alerts only the thresholds it is still past, and may warn of an overrun again', function () {
        $budget = AiBudget::factory()->forProject($this->project)->costing(50)->create();
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-02 10:00:00', 'input_tokens' => 45_000_000, 'output_tokens' => 0]);
        $state = AiBudgetState::query()->create(['ai_budget_id' => $budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [50, 80], 'projected_fired_at' => now()]);

        // 45 of 75 is 60%.
        $this->actingAs($this->admin)->put(route('ai.budgets.update', ['project' => $this->project, 'aiBudget' => $budget->uuid]), [...$this->valid, 'payer' => 'all', 'amount' => '75'])->assertSessionHasNoErrors();

        expect($state->refresh())->fired_thresholds->toBe([50])->projected_fired_at->toBeNull();
    });

    it('deletes one', function () {
        $budget = AiBudget::factory()->forProject($this->project)->create();

        $this->actingAs($this->admin)->delete(route('ai.budgets.destroy', ['project' => $this->project, 'aiBudget' => $budget->uuid]))->assertRedirect();

        expect(AiBudget::query()->count())->toBe(0);
    });

    it('refuses a member, before telling what is wrong with the input', function () {
        $budget = AiBudget::factory()->forProject($this->project)->create();

        $this->actingAs($this->member)->post(route('ai.budgets.store', ['project' => $this->project]), ['amount' => ''])->assertForbidden();
        $this->actingAs($this->member)->put(route('ai.budgets.update', ['project' => $this->project, 'aiBudget' => $budget->uuid]), $this->valid)->assertForbidden();
        $this->actingAs($this->member)->delete(route('ai.budgets.destroy', ['project' => $this->project, 'aiBudget' => $budget->uuid]))->assertForbidden();
    });

    it('does not reach another project\'s budget', function () {
        $other = AiBudget::factory()->create();

        $this->actingAs($this->admin)->delete(route('ai.budgets.destroy', ['project' => $this->project, 'aiBudget' => $other->uuid]))->assertNotFound();
    });
});
