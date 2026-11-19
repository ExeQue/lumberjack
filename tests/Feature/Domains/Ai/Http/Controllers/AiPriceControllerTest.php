<?php

declare(strict_types=1);

use App\Domains\Ai\Actions\DeleteAiPriceAction;
use App\Domains\Ai\Actions\SaveAiPriceAction;
use App\Domains\Ai\Actions\SetProjectAiPayerAction;
use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Http\Controllers\AiPriceController;
use App\Domains\Ai\Http\Requests\SaveAiPriceRequest;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Policies\AiPricePolicy;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

covers(AiPriceController::class, SaveAiPriceRequest::class, SaveAiPriceAction::class, DeleteAiPriceAction::class, SetProjectAiPayerAction::class, AiPricePolicy::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:30:00');
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->valid = [
        'scope' => 'project',
        'model_pattern' => 'claude-sonnet-*',
        'valid_from' => '2026-11-01T00:00:00+01:00',
        'input' => '3',
        'output' => '15',
        'cache_read' => '0.3',
    ];
});

describe('the prices page', function () {
    it('lists the project\'s prices and its team\'s, and the models used lately', function () {
        $team = AiPrice::factory()->forTeamOf($this->project)->create();
        $own = AiPrice::factory()->forProject($this->project)->create();
        AiPrice::factory()->forProject(Project::factory()->for($this->project->team)->create())->create();
        AiPrice::factory()->create();
        AiUsageDaily::factory()->forProject($this->project)->create(['provider' => 'openai', 'model' => 'gpt-5', 'day' => now()->subDays(3)]);
        AiUsageDaily::factory()->forProject($this->project)->create(['provider' => 'anthropic', 'model' => 'claude-opus-4-5', 'day' => now()]);
        // Not used lately.
        AiUsageDaily::factory()->forProject($this->project)->create(['model' => 'claude-2', 'day' => now()->subDays(AiPriceController::MODELS_DAYS + 1)]);

        $this->actingAs($this->member)
            ->get(route('ai.prices.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai/prices')
                ->has('prices', 2)
                ->where('prices', fn ($prices) => collect($prices)->pluck('uuid')->sort()->values()->all() === collect([$team->uuid, $own->uuid])->sort()->values()->all())
                ->where('models', [['provider' => 'anthropic', 'model' => 'claude-opus-4-5'], ['provider' => 'openai', 'model' => 'gpt-5']])
                ->where('default_payer', 'us')
                ->where('can_update', false));
    });

    it('loads only the keys its prices name, and offers key owners as type and id', function () {
        $priced = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 'a:b')->create();
        AiKey::factory()->forProject($this->project)->ownedBy('user', 7)->create();
        AiPrice::factory()->forKey($priced)->create();

        $this->actingAs($this->member)
            ->get(route('ai.prices.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prices.0.key.uuid', $priced->uuid)
                ->where('owners', fn ($owners) => collect($owners)->sortBy('type')->values()->all() === [['type' => 'tenant', 'id' => 'a:b'], ['type' => 'user', 'id' => '7']]));
    });

    it('offers the start of the day of the first kept AI call, so a new price covers the usage already there', function () {
        $this->actingAs($this->member)->get(route('ai.prices.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('first_used_at', null));

        AiKey::factory()->forProject($this->project)->create(['first_seen_at' => '2026-11-10 14:30:00']);
        AiKey::factory()->forProject($this->project)->create(['first_seen_at' => '2026-11-12 09:00:00']);
        AiKey::factory()->create(['first_seen_at' => '2026-11-01 09:00:00']);

        $this->actingAs($this->member)->get(route('ai.prices.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page->where('first_used_at', '2026-11-10T00:00:00+00:00'));
    });
});

describe('adding a price', function () {
    it('adds one for the project, or for the whole team', function () {
        $this->actingAs($this->admin)->post(route('ai.prices.store', ['project' => $this->project]), $this->valid)->assertRedirect();
        $this->actingAs($this->admin)->post(route('ai.prices.store', ['project' => $this->project]), [...$this->valid, 'team_wide' => true, 'provider' => 'Anthropic'])->assertRedirect();

        $prices = AiPrice::query()->orderBy('id')->get();
        expect($prices)->toHaveCount(2)
            ->and($prices[0])->project_id->toBe($this->project->id)->scope->toBe(AiPriceScope::Project)->cache_read->toBe(0.3)->cache_write->toBeNull()
            ->and($prices[0]->valid_from->toDateTimeString())->toBe('2026-10-31 23:00:00')
            ->and($prices[1])->project_id->toBeNull()->team_id->toBe($this->project->team_id)->provider->toBe('anthropic');
    });

    it('adds one for a key owner, or for one key by its fingerprint', function () {
        $key = AiKey::factory()->forProject($this->project)->create();

        $this->actingAs($this->admin)->post(route('ai.prices.store', ['project' => $this->project]), [...$this->valid, 'scope' => 'owner', 'owner_type' => 'tenant', 'owner_id' => '7', 'team_wide' => true])->assertRedirect();
        $this->actingAs($this->admin)->post(route('ai.prices.store', ['project' => $this->project]), [...$this->valid, 'scope' => 'key', 'key' => $key->uuid])->assertRedirect();

        $prices = AiPrice::query()->orderBy('id')->get();
        expect($prices[0])->scope->toBe(AiPriceScope::Owner)->scope_type->toBe('tenant')->scope_id->toBe('7')->project_id->toBe($this->project->id)
            ->and($prices[1])->scope->toBe(AiPriceScope::Key)->scope_id->toBe($key->fingerprint)->scope_type->toBeNull();
    });

    it('refuses what does not make a price', function (Closure $changes, string $field) {
        $this->actingAs($this->admin)
            ->post(route('ai.prices.store', ['project' => $this->project]), [...$this->valid, ...$changes()])
            ->assertSessionHasErrors($field);

        expect(AiPrice::query()->count())->toBe(0);
    })->with([
        'no model' => [fn () => ['model_pattern' => ''], 'model_pattern'],
        'a model with a space' => [fn () => ['model_pattern' => 'claude sonnet'], 'model_pattern'],
        'a negative price' => [fn () => ['input' => '-1'], 'input'],
        'no output price' => [fn () => ['output' => ''], 'output'],
        'a price beyond any' => [fn () => ['cache_write' => '1000001'], 'cache_write'],
        'no start' => [fn () => ['valid_from' => 'soon'], 'valid_from'],
        'an unknown scope' => [fn () => ['scope' => 'planet'], 'scope'],
        'an owner without a key' => [fn () => ['scope' => 'owner', 'owner_type' => 'tenant'], 'owner_id'],
        'another project\'s key' => [fn () => ['scope' => 'key', 'key' => AiKey::factory()->create()->uuid], 'key'],
    ]);

    it('refuses a member, before telling them what is invalid', function () {
        $this->actingAs($this->member)->post(route('ai.prices.store', ['project' => $this->project]), $this->valid)->assertForbidden();
        $this->actingAs($this->member)->post(route('ai.prices.store', ['project' => $this->project]), [])->assertForbidden();
        $this->actingAs($this->member)->put(route('ai.payer', ['project' => $this->project]), [])->assertForbidden();

        expect(AiPrice::query()->count())->toBe(0);
    });
});

describe('changing a price', function () {
    it('never moves a price to another team, even when the project moved meanwhile', function () {
        $price = AiPrice::factory()->forTeamOf(Project::factory()->create())->create();

        expect(fn () => app(SaveAiPriceAction::class)->execute($this->project, $price, AiPriceScope::Project, ['model_pattern' => 'x', 'valid_from' => now()->toImmutable(), 'input' => 1, 'output' => 1, 'team_wide' => true]))
            ->toThrow(NotFoundHttpException::class)
            ->and($price->refresh()->team_id)->not->toBe($this->project->team_id);
    });

    it('changes or deletes the project\'s own price and its team\'s', function () {
        $team = AiPrice::factory()->forTeamOf($this->project)->create();
        $own = AiPrice::factory()->forProject($this->project)->create();

        $this->actingAs($this->admin)->put(route('ai.prices.update', ['project' => $this->project, 'aiPrice' => $team->uuid]), [...$this->valid, 'team_wide' => true, 'input' => '4'])->assertRedirect();
        $this->actingAs($this->admin)->delete(route('ai.prices.destroy', ['project' => $this->project, 'aiPrice' => $own->uuid]))->assertRedirect();

        expect($team->refresh())->input->toBe(4.0)->project_id->toBeNull()
            ->and(AiPrice::query()->whereKey($own->id)->exists())->toBeFalse();
    });

    it('refuses another project\'s or team\'s price, and a member', function (Closure $price, Closure $user) {
        $price = $price($this->project);

        $this->actingAs($user($this))->put(route('ai.prices.update', ['project' => $this->project, 'aiPrice' => $price->uuid]), $this->valid)->assertForbidden();
        $this->actingAs($user($this))->delete(route('ai.prices.destroy', ['project' => $this->project, 'aiPrice' => $price->uuid]))->assertForbidden();

        expect(AiPrice::query()->whereKey($price->id)->exists())->toBeTrue();
    })->with([
        'another project\'s in the team' => [fn (Project $project) => AiPrice::factory()->forProject(Project::factory()->for($project->team)->create())->create(), fn ($test) => $test->admin],
        'another team\'s' => [fn () => AiPrice::factory()->create(), fn ($test) => $test->admin],
        'by a member' => [fn (Project $project) => AiPrice::factory()->forProject($project)->create(), fn ($test) => $test->member],
    ]);
});

describe('who pays by default', function () {
    it('lets an administrator set it', function () {
        $this->actingAs($this->admin)->put(route('ai.payer', ['project' => $this->project]), ['payer' => 'customer'])->assertRedirect();

        expect($this->project->refresh()->ai_default_payer)->toBe(AiPayer::Customer);
    });

    it('cannot be left empty, or set by a member', function () {
        $this->actingAs($this->admin)->put(route('ai.payer', ['project' => $this->project]), ['payer' => ''])->assertSessionHasErrors('payer');
        $this->actingAs($this->member)->put(route('ai.payer', ['project' => $this->project]), ['payer' => 'customer'])->assertForbidden();

        expect($this->project->refresh()->ai_default_payer)->toBe(AiPayer::Us);
    });
});
