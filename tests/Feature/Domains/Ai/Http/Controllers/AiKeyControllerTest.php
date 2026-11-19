<?php

declare(strict_types=1);

use App\Domains\Ai\Actions\SetAiKeyNameAction;
use App\Domains\Ai\Actions\SetAiKeyPayerAction;
use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Http\Controllers\AiKeyController;
use App\Domains\Ai\Http\Requests\LookUpAiKeyRequest;
use App\Domains\Ai\Http\Requests\SetAiKeyNameRequest;
use App\Domains\Ai\Http\Requests\SetAiPayerRequest;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Support\AiKeyFingerprint;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

covers(AiKeyController::class, LookUpAiKeyRequest::class, SetAiPayerRequest::class, SetAiKeyPayerAction::class, SetAiKeyNameRequest::class, SetAiKeyNameAction::class, AiKeyFingerprint::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:30:00');
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->key = AiKey::factory()->forProject($this->project)->labeled('prod')->ownedBy('tenant', 7)->create();
});

describe('a key\'s page', function () {
    it('shows the key, the price each model is charged at and its calls newest first', function () {
        $price = AiPrice::factory()->forTeamOf($this->project)->model('claude-*')->create();
        AiUsageHourly::factory()->forKey($this->key)->create();
        AiUsageHourly::factory()->forKey($this->key)->create(['model' => 'claude-opus-4-5']);
        AiUsageHourly::factory()->forKey($this->key)->create(['model' => 'gpt-5']);
        AiCall::factory()->forKey($this->key)->create(['occurred_at' => now()->subHour()]);
        $newest = AiCall::factory()->forKey($this->key)->failed()->create(['occurred_at' => now()->subMinute()]);
        AiCall::factory()->forProject($this->project)->create();

        $this->actingAs($this->member)
            ->get(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $this->key->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai/key')
                ->where('ai_key.label', 'prod')
                ->where('ai_key.payer', null)
                // An owned key is the customer's unless it says otherwise.
                ->where('ai_key.effective_payer', 'customer')
                ->has('prices', 3)
                ->where('prices.0.model', 'claude-opus-4-5')
                ->where('prices.0.price.uuid', $price->uuid)
                ->where('prices.2.model', 'gpt-5')
                ->where('prices.2.price', null)
                ->has('calls', 2)
                ->where('calls.0.id', $newest->id)
                ->where('calls.0.status', 'error')
                ->where('can_update', false));
    });

    it('prices the calls naming another owner than the key\'s at that owner\'s price', function () {
        AiPrice::factory()->forTeamOf($this->project)->create();
        $other = AiPrice::factory()->forOwner($this->project, 'tenant', '8')->create();
        AiUsageHourly::factory()->forKey($this->key)->create();
        AiUsageHourly::factory()->forKey($this->key)->ownedBy('tenant', 8)->create();

        $this->actingAs($this->member)
            ->get(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $this->key->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('prices', 2)
                ->where('prices', fn ($prices) => collect($prices)->firstWhere('owner', ['type' => 'tenant', 'id' => '8'])['price']['uuid'] === $other->uuid
                    && collect($prices)->firstWhere('owner', null)['price']['uuid'] !== $other->uuid));
    });

    it('lists the prices of the models used in the environment picked', function () {
        AiUsageHourly::factory()->forKey($this->key)->create(['environment' => 'production']);
        AiUsageHourly::factory()->forKey($this->key)->create(['environment' => 'staging', 'model' => 'gpt-5']);

        $this->actingAs($this->member)
            ->get(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $this->key->uuid, 'environment' => 'staging']))
            ->assertInertia(fn (Assert $page) => $page->has('prices', 1)->where('prices.0.model', 'gpt-5'));
    });

    it('lists only the failed calls when asked', function () {
        AiCall::factory()->forKey($this->key)->create();
        $failed = AiCall::factory()->forKey($this->key)->failed()->create();

        $this->actingAs($this->member)
            ->get(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $this->key->uuid, 'errors' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.errors', true)
                ->has('calls', 1)
                ->where('calls.0.id', $failed->id));
    });

    it('does not open another project\'s key', function () {
        $other = AiKey::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $other->uuid]))
            ->assertNotFound();
    });
});

describe('who pays for a key', function () {
    it('lets an administrator set it, and set it back to inherit', function () {
        $this->actingAs($this->admin)
            ->put(route('ai.keys.payer', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['payer' => 'us'])
            ->assertRedirect();

        expect($this->key->refresh()->payer)->toBe(AiPayer::Us);

        $this->actingAs($this->admin)
            ->put(route('ai.keys.payer', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['payer' => ''])
            ->assertRedirect();

        expect($this->key->refresh()->payer)->toBeNull();
    });

    it('refuses a member', function () {
        $this->actingAs($this->member)
            ->put(route('ai.keys.payer', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['payer' => 'us'])
            ->assertForbidden();

        expect($this->key->refresh()->payer)->toBeNull();
    });

    it('refuses a payer it does not know', function () {
        $this->actingAs($this->admin)
            ->put(route('ai.keys.payer', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['payer' => 'nobody'])
            ->assertSessionHasErrors('payer');
    });
});

describe('a key\'s name', function () {
    it('lets an administrator name it and take the name away, shown before the label', function () {
        $name = fn (string $name) => $this->actingAs($this->admin)
            ->put(route('ai.keys.name', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['name' => $name])
            ->assertRedirect();

        $name('  Checkout  ');

        expect($this->key->refresh())->name->toBe('Checkout')->label->toBe('prod')->displayName()->toBe('Checkout')
            ->and(AiUsagePresenter::key($this->key))->name->toBe('Checkout')->label->toBe('prod');

        $name('');

        expect($this->key->refresh())->name->toBeNull()->displayName()->toBe('prod');
    });

    it('is shown as the name, else the label, else the short id', function (?string $name, ?string $label, Closure $shown) {
        $key = AiKey::factory()->forProject($this->project)->create(['name' => $name, 'label' => $label]);

        expect($key->displayName())->toBe($shown($key));
    })->with([
        'named' => ['Checkout', 'prod', fn () => 'Checkout'],
        'labeled' => [null, 'prod', fn () => 'prod'],
        'neither' => [null, null, fn (AiKey $key) => $key->shortId()],
    ]);

    it('refuses a member, a name too long and another project\'s key', function () {
        $this->actingAs($this->member)
            ->put(route('ai.keys.name', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['name' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->put(route('ai.keys.name', ['project' => $this->project, 'aiKey' => $this->key->uuid]), ['name' => str_repeat('x', 65)])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->admin)
            ->put(route('ai.keys.name', ['project' => $this->project, 'aiKey' => AiKey::factory()->create()->uuid]), ['name' => 'x'])
            ->assertNotFound();

        expect($this->key->refresh()->name)->toBeNull();
    });
});

describe('looking a key up', function () {
    it('opens the key one of the hashes the browser sent matches, whatever its driver', function () {
        $hash = hash('sha256', 'anthropic:sk-ant-secret');
        $key = AiKey::factory()->forProject($this->project)->create([
            'fingerprint' => AiKeyFingerprint::of($this->project->ai_key_secret, $hash),
        ]);

        $this->actingAs($this->member)
            ->post(route('ai.keys.lookup', ['project' => $this->project]), ['hashes' => [
                hash('sha256', 'openai:sk-ant-secret'),
                strtoupper($hash),
            ]])
            ->assertRedirect(route('ai.keys.show', ['project' => $this->project, 'aiKey' => $key->uuid]));
    });

    it('says when no call was made with the key', function () {
        $this->actingAs($this->member)
            ->from(route('ai.index', ['project' => $this->project]))
            ->post(route('ai.keys.lookup', ['project' => $this->project]), ['hashes' => [hash('sha256', 'openai:sk-unknown')]])
            ->assertRedirect(route('ai.index', ['project' => $this->project]))
            ->assertSessionHasErrors(['hashes' => __('ui/ai.lookup.not_found')]);
    });

    it('keeps the hashes out of the session when they are refused', function () {
        $this->actingAs($this->member)
            ->post(route('ai.keys.lookup', ['project' => $this->project]), ['hashes' => [str_repeat('g', 64)]])
            ->assertSessionHasErrors('hashes.0')
            ->assertSessionMissing('_old_input.hashes');
    });

    it('rejects anything but a list of 64 hexadecimal characters each, a raw key above all', function (mixed $hashes, string $error) {
        $this->actingAs($this->member)
            ->post(route('ai.keys.lookup', ['project' => $this->project]), ['hashes' => $hashes])
            ->assertSessionHasErrors($error);
    })->with([
        'a raw key' => [['sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz'], 'hashes.0'],
        'a raw key among hashes' => [[str_repeat('a', 64), 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz'], 'hashes.1'],
        'one character short' => [[str_repeat('a', 63)], 'hashes.0'],
        'one character long' => [[str_repeat('a', 65)], 'hashes.0'],
        'not hexadecimal' => [[str_repeat('g', 64)], 'hashes.0'],
        'nothing' => [[], 'hashes'],
        'a single string' => [str_repeat('a', 64), 'hashes'],
        'too many' => [array_fill(0, 33, str_repeat('a', 64)), 'hashes'],
    ]);

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->post(route('ai.keys.lookup', ['project' => $this->project]), ['hashes' => [str_repeat('a', 64)]])
            ->assertForbidden();
    });
});
