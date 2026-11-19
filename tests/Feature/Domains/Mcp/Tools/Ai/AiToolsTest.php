<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Ai\DeleteAiBudget;
use App\Domains\Mcp\Tools\Ai\DeleteAiPrice;
use App\Domains\Mcp\Tools\Ai\GetAiKey;
use App\Domains\Mcp\Tools\Ai\GetAiUsage;
use App\Domains\Mcp\Tools\Ai\ListAiBudgets;
use App\Domains\Mcp\Tools\Ai\ListAiPrices;
use App\Domains\Mcp\Tools\Ai\ListAiSpenders;
use App\Domains\Mcp\Tools\Ai\SaveAiBudget;
use App\Domains\Mcp\Tools\Ai\SaveAiPrice;
use App\Domains\Mcp\Tools\Ai\SetAiDefaultPayer;
use App\Domains\Mcp\Tools\Ai\UpdateAiKey;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Mcp\CallsMcpTools;

covers(GetAiUsage::class, ListAiSpenders::class, GetAiKey::class, ListAiBudgets::class, ListAiPrices::class, SetAiDefaultPayer::class, SaveAiBudget::class, SaveAiPrice::class, UpdateAiKey::class, DeleteAiBudget::class, DeleteAiPrice::class, UsesAiUsage::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->travelTo('2026-11-18 12:30:00');
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')->allowing(McpArea::Ai, McpLevel::Manage)->create();
    $this->token->projects()->attach($this->project);
    $this->key = AiKey::factory()->forProject($this->project)->labeled('prod')->ownedBy('tenant', 7)->create();
    $this->price = AiPrice::factory()->forTeamOf($this->project)->model('claude-*')->costing(3, 15)->create();
    $this->budget = AiBudget::factory()->forProject($this->project)->costing(0.01)->create(['name' => 'Monthly']);
    $this->other = Project::factory()->create(['slug' => 'elsewhere']);
    $this->otherKey = AiKey::factory()->forProject($this->other)->create();
    $this->expectNothingChanged = fn () => expect($this->budget->refresh()->amount)->toEqual(0.01)
        ->and($this->price->refresh()->output)->toEqual(15)
        ->and($this->key->refresh()->name)->toBeNull()
        ->and(AiBudget::query()->count())->toBe(1)
        ->and(AiPrice::query()->count())->toBe(1)
        ->and($this->project->refresh()->ai_default_payer)->toBe(AiPayer::Us);
});

describe('reading usage', function () {
    it('totals the period by payer and model, against the period before, and warns of unpriced tokens', function () {
        AiUsageHourly::factory()->forKey($this->key)->create();
        AiUsageHourly::factory()->forProject($this->project)->create(['model' => 'gpt-5']);
        AiUsageHourly::factory()->forKey($this->key)->create(['hour' => now()->subDays(45)->startOfHour()]);

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'get_ai_usage')->assertOk());

        expect($result['period'])->toBe('30d')
            ->and($result['currency'])->toBe('USD')
            ->and($result['summary'])->toMatchArray(['calls' => 2, 'tokens' => 2400, 'cost_ours' => 0, 'cost_customer' => 0.006, 'unpriced_tokens' => 1200])
            ->and($result['summary']['previous'])->toMatchArray(['calls' => 1, 'cost_customer' => 0.006])
            ->and(array_column($result['models'], 'model'))->toBe(['claude-sonnet-4-5', 'gpt-5'])
            ->and($result['models'][0])->not->toHaveKey('fingerprint')
            ->and($result['warning'])->toBe('1,200 tokens have no price, so their cost is missing.');
    });

    it('has no warning when every token is priced', function () {
        AiUsageHourly::factory()->forKey($this->key)->create();

        expect($this->toolResult($this->callTool('lj_mcp_secret', 'get_ai_usage', ['period' => '24h']))['warning'])->toBeNull();
    });

    it('lists the costliest keys, owners or actors a page at a time', function (string $kind, Closure $expected) {
        AiUsageHourly::factory()->forKey($this->key)->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_ai_spenders', ['kind' => $kind])->assertOk());

        expect($result)->toMatchArray(['kind' => $kind, 'page' => 1, 'last_page' => 1, 'total' => 1, 'has_more' => false])
            ->and($result['spenders'][0])->toMatchArray([...$expected->call($this), 'cost' => 0.006, 'previous_cost' => 0]);
    })->with([
        'keys' => ['keys', fn (): array => ['key' => ['uuid' => $this->key->uuid, 'short_id' => $this->key->shortId(), 'name' => null, 'label' => 'prod', 'provider' => $this->key->provider, 'owner' => ['type' => 'tenant', 'id' => '7'], 'payer' => null]]],
        'owners' => ['owners', fn (): array => ['reference' => ['type' => 'tenant', 'id' => '7']]],
    ]);

    it('searches spenders with * standing for anything', function () {
        AiUsageHourly::factory()->forKey($this->key)->create();
        $search = fn (string $term): int => $this->toolResult($this->callTool('lj_mcp_secret', 'list_ai_spenders', ['kind' => 'owners', 'search' => $term])->assertOk())['total'];

        expect($search('ten*:7'))->toBe(1)->and($search('ten*:8'))->toBe(0)->and($search('%'))->toBe(0);
    });

    it('shows a key by its short id with its prices, budgets and failed calls', function () {
        AiUsageHourly::factory()->forKey($this->key)->create();
        AiCall::factory()->forKey($this->key)->create(['occurred_at' => now()->subHour()]);
        $failed = AiCall::factory()->forKey($this->key)->failed()->create(['occurred_at' => now()->subMinute()]);
        $budget = AiBudget::factory()->forKey($this->key)->create();

        $response = $this->callTool('lj_mcp_secret', 'get_ai_key', ['key' => $this->key->shortId(), 'only_failed' => true])->assertOk();
        $result = $this->toolResult($response);

        expect($result['key'])->toMatchArray(['uuid' => $this->key->uuid, 'short_id' => $this->key->shortId(), 'label' => 'prod', 'payer' => null, 'effective_payer' => 'customer'])
            ->and($result['summary'])->toMatchArray(['calls' => 1, 'cost_customer' => 0.006])
            ->and($result['prices'][0]['price']['uuid'])->toBe($this->price->uuid)
            ->and(array_column($result['budgets'], 'uuid'))->toBe([$budget->uuid])
            ->and(array_column($result['calls'], 'id'))->toBe([$failed->id])
            ->and($response->getContent())->not->toContain($this->key->fingerprint);
    });

    it('lists the budgets with where each stands', function () {
        $calm = AiBudget::factory()->forProject($this->project)->costing(50)->create(['name' => 'Roomy']);

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_ai_budgets')->assertOk());

        expect(array_column($result['budgets'], 'status', 'uuid'))->toBe([$this->budget->uuid => 'ok', $calm->uuid => 'ok'])
            ->and($result['budgets'][0])->toMatchArray(['name' => 'Monthly', 'scope' => 'project', 'period' => 'month', 'basis' => 'cost', 'amount' => 0.01])
            ->and($result['budgets'][0]['usage'])->toHaveKeys(['spent', 'amount', 'percent', 'projected']);
    });

    it('marks a budget over once it is used up', function () {
        AiUsageHourly::factory()->forKey($this->key)->create(['input_tokens' => 4000]);

        expect($this->toolResult($this->callTool('lj_mcp_secret', 'list_ai_budgets'))['budgets'][0]['status'])->toBe('over');
    });
});

describe('changing budgets, prices and keys', function () {
    it('adds a budget for a key', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'save_ai_budget', [
            'scope' => 'key', 'keys' => [$this->key->uuid], 'period' => 'day', 'basis' => 'tokens', 'amount' => 5000,
        ])->assertOk());

        $budget = AiBudget::query()->where('uuid', $result['budget']['uuid'])->sole();
        expect($budget->project_id)->toBe($this->project->id)
            ->and($budget->fingerprints())->toBe([$this->key->fingerprint])
            ->and($result['budget'])->toMatchArray(['scope' => 'key', 'period' => 'day', 'basis' => 'tokens', 'status' => 'ok'])
            ->and($result['budget']['keys'][0]['value'])->toBe($this->key->uuid);
    });

    it('changes only what is passed on a budget', function () {
        $this->callTool('lj_mcp_secret', 'save_ai_budget', ['budget' => $this->budget->uuid, 'amount' => 25])->assertOk();

        expect($this->budget->refresh()->amount)->toEqual(25)
            ->and($this->budget->name)->toBe('Monthly')
            ->and($this->budget->period->value)->toBe('month');
    });

    it('adds a price for a key and changes it', function () {
        $added = $this->toolResult($this->callTool('lj_mcp_secret', 'save_ai_price', [
            'scope' => 'key', 'key' => $this->key->uuid, 'model_pattern' => 'gpt-*', 'valid_from' => '2026-01-01T00:00:00Z', 'input' => 1.25, 'output' => 10,
        ])->assertOk())['price'];

        $this->callTool('lj_mcp_secret', 'save_ai_price', ['price' => $added['uuid'], 'output' => 12])->assertOk();

        $price = AiPrice::query()->where('uuid', $added['uuid'])->sole();
        expect($price->scope_id)->toBe($this->key->fingerprint)
            ->and($price->output)->toEqual(12)
            ->and($price->input)->toEqual(1.25)
            ->and($added['key']['uuid'])->toBe($this->key->uuid)
            ->and($added)->not->toHaveKey('fingerprint');
    });

    it('names a key and sets who pays for it', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'update_ai_key', ['key' => $this->key->uuid, 'name' => 'Checkout', 'payer' => 'us'])->assertOk());

        expect($this->key->refresh()->name)->toBe('Checkout')
            ->and($this->key->payer)->toBe(AiPayer::Us)
            ->and($result['key'])->toMatchArray(['name' => 'Checkout', 'payer' => 'us', 'effective_payer' => 'us']);
    });

    it('leaves out what is not passed and sets a key back to inherit', function () {
        $this->key->update(['name' => 'Checkout', 'payer' => AiPayer::Us]);

        $this->callTool('lj_mcp_secret', 'update_ai_key', ['key' => $this->key->uuid, 'payer' => null])->assertOk();

        expect($this->key->refresh()->name)->toBe('Checkout')->and($this->key->payer)->toBeNull();
    });

    it('lists the team\'s and the project\'s prices, a key\'s by its short id, never another project\'s', function () {
        $keyPrice = AiPrice::factory()->forKey($this->key)->create();
        AiPrice::factory()->forProject($this->other)->create();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'list_ai_prices')->assertOk());

        expect($result)->toMatchArray(['currency' => $this->project->team->currency, 'default_payer' => 'us'])
            ->and(collect($result['prices'])->pluck('uuid')->sort()->values()->all())->toBe(collect([$this->price->uuid, $keyPrice->uuid])->sort()->values()->all())
            ->and(collect($result['prices'])->firstWhere('uuid', $keyPrice->uuid)['key']['short_id'])->toBe($this->key->shortId())
            ->and(json_encode($result))->not->toContain($this->key->fingerprint);
    });

    it('sets who pays for keys that do not say', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'set_ai_default_payer', ['payer' => 'customer'])->assertOk());

        expect($result)->toBe(['default_payer' => 'customer'])
            ->and($this->project->refresh()->ai_default_payer)->toBe(AiPayer::Customer);
    });

    it('deletes a budget and a price', function () {
        $this->callTool('lj_mcp_secret', 'delete_ai_budget', ['budget' => $this->budget->uuid])->assertOk();
        $this->callTool('lj_mcp_secret', 'delete_ai_price', ['price' => $this->price->uuid])->assertOk();

        expect(AiBudget::query()->whereKey($this->budget->id)->exists())->toBeFalse()
            ->and(AiPrice::query()->whereKey($this->price->id)->exists())->toBeFalse();
    });
});

describe('what the tools refuse', function () {
    dataset('changing tools', [
        'save budget' => ['save_ai_budget', fn (): array => ['budget' => $this->budget->uuid, 'amount' => 25]],
        'delete budget' => ['delete_ai_budget', fn (): array => ['budget' => $this->budget->uuid]],
        'save price' => ['save_ai_price', fn (): array => ['price' => $this->price->uuid, 'output' => 99]],
        'delete price' => ['delete_ai_price', fn (): array => ['price' => $this->price->uuid]],
        'update key' => ['update_ai_key', fn (): array => ['key' => $this->key->uuid, 'name' => 'Changed']],
        'default payer' => ['set_ai_default_payer', fn (): array => ['payer' => 'customer']],
    ]);

    it('refuses a read-only token', function (string $tool, Closure $arguments) {
        $this->token->update(['permissions' => $this->token->permissions->with(McpArea::Ai, new McpAreaGrant(McpLevel::Read))]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments->call($this))))->toBeTrue();
        ($this->expectNothingChanged)();
    })->with('changing tools');

    it('refuses when the maker may not administer the project', function (string $tool, Closure $arguments) {
        // A token stops when its maker becomes a member; this stands in for a policy saying no.
        Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, ['update', 'delete'], true) ? false : null);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments->call($this))))->toBeTrue();
        ($this->expectNothingChanged)();
    })->with('changing tools');

    it('stops a token whose maker became a member', function () {
        $this->project->team->members()->updateExistingPivot($this->token->user_id, ['role' => TeamRole::Member->value]);

        $this->callTool('lj_mcp_secret', 'save_ai_budget', ['budget' => $this->budget->uuid, 'amount' => 25])->assertUnauthorized();
        ($this->expectNothingChanged)();
    });

    it('returns validation errors', function (string $tool, array $arguments, string $error) {
        $response = $this->callTool('lj_mcp_secret', $tool, $arguments);

        expect($this->wasRefused($response))->toBeTrue()
            ->and((string) $response->json('result.content.0.text'))->toContain($error);
        ($this->expectNothingChanged)();
    })->with([
        'a budget without an amount' => ['save_ai_budget', ['scope' => 'project', 'period' => 'month', 'basis' => 'cost'], 'amount'],
        'a budget for another project\'s key' => ['save_ai_budget', fn (): array => ['scope' => 'key', 'keys' => [$this->otherKey->uuid], 'period' => 'month', 'basis' => 'cost', 'amount' => 5], 'keys.0'],
        'the same owner twice' => ['save_ai_budget', ['scope' => 'owner', 'owners' => [['type' => 'tenant', 'id' => '7'], ['type' => 'tenant', 'id' => '7']], 'period' => 'month', 'basis' => 'cost', 'amount' => 5], 'duplicate'],
        'a negative price' => ['save_ai_price', ['scope' => 'project', 'model_pattern' => 'gpt-*', 'valid_from' => '2026-01-01', 'input' => -1, 'output' => 1], 'input'],
        'a price for another project\'s key' => ['save_ai_price', fn (): array => ['scope' => 'key', 'key' => $this->otherKey->uuid, 'model_pattern' => 'gpt-*', 'valid_from' => '2026-01-01', 'input' => 1, 'output' => 1], 'key'],
        'a long key name' => ['update_ai_key', fn (): array => ['key' => $this->key->uuid, 'name' => str_repeat('x', 65)], 'name'],
        'an unknown payer' => ['update_ai_key', fn (): array => ['key' => $this->key->uuid, 'payer' => 'nobody'], 'payer'],
        'no default payer' => ['set_ai_default_payer', [], 'payer'],
    ]);

    it('refuses another team\'s project', function (string $tool) {
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, ['project' => 'elsewhere', 'key' => $this->otherKey->uuid])))->toBeTrue();
    })->with(['get_ai_usage', 'list_ai_spenders', 'get_ai_key', 'list_ai_budgets', 'list_ai_prices', 'update_ai_key', 'set_ai_default_payer']);

    it('does not find another project\'s key, budget or price', function (string $tool, Closure $arguments) {
        $this->otherKey->update(['name' => 'Theirs']);
        $budget = AiBudget::factory()->forProject($this->other)->create();
        $price = AiPrice::factory()->forProject($this->other)->create();

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments($this->otherKey, $budget, $price))))->toBeTrue()
            ->and($this->otherKey->refresh()->name)->toBe('Theirs')
            ->and($budget->refresh()->amount)->toEqual(100)
            ->and(AiPrice::query()->whereKey($price->id)->exists())->toBeTrue();
    })->with([
        'get key' => ['get_ai_key', fn (AiKey $key): array => ['key' => $key->uuid]],
        'update key' => ['update_ai_key', fn (AiKey $key): array => ['key' => $key->uuid, 'name' => 'Mine']],
        'save budget' => ['save_ai_budget', fn (AiKey $key, AiBudget $budget): array => ['budget' => $budget->uuid, 'amount' => 1]],
        'delete budget' => ['delete_ai_budget', fn (AiKey $key, AiBudget $budget): array => ['budget' => $budget->uuid]],
        'delete price' => ['delete_ai_price', fn (AiKey $key, AiBudget $budget, AiPrice $price): array => ['price' => $price->uuid]],
    ]);
});
