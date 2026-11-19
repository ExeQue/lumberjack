<?php

declare(strict_types=1);

use App\Domains\Ai\Http\Controllers\AiTargetController;
use App\Domains\Ai\Http\Requests\SearchAiTargetsRequest;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Queries\AiTargetSearchQuery;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

covers(AiTargetController::class, SearchAiTargetsRequest::class, AiTargetSearchQuery::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 12:00:00');
    $this->project = Project::factory()->create();
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->search = fn (string $kind, string $q = '') => $this->actingAs($this->user)
        ->getJson(route('ai.targets.search', ['project' => $this->project, 'kind' => $kind, 'q' => $q]))
        ->assertOk()
        ->json();
    $this->daily = fn (string $column, string $type, string $id, string $day) => AiUsageDaily::factory()->forProject($this->project)
        ->create([$column.'_type' => $type, $column.'_id' => $id, 'day' => $day]);
});

describe('searching keys', function () {
    it('finds a key however long ago it was used, by name, label, short id or owner', function () {
        AiKey::factory()->forProject($this->project)->count(210)->create();
        $old = AiKey::factory()->forProject($this->project)->labeled('legacy-batch')->ownedBy('tenant', 99)
            ->lastSeen(now()->subYear())->create(['name' => 'Night jobs']);

        foreach (['night', 'LEGACY', $old->shortId(), 'tenant:99'] as $term) {
            expect(collect(($this->search)('keys', $term))->pluck('uuid')->all())->toBe([$old->uuid]);
        }
    });

    it('gives back the latest 20 per provider, so a busy one hides no other', function () {
        AiKey::factory()->forProject($this->project)->count(30)->create(['provider' => 'anthropic']);
        AiKey::factory()->forProject($this->project)->count(3)->lastSeen(now()->subMonth())->create(['provider' => 'openai']);

        expect(collect(($this->search)('keys'))->countBy('provider')->all())->toBe(['anthropic' => 20, 'openai' => 3]);
    });

    it('never gives another project\'s keys, and searches % and _ as themselves', function () {
        AiKey::factory()->labeled('prod')->create();
        AiKey::factory()->forProject($this->project)->labeled('prod')->create();
        $underscored = AiKey::factory()->forProject($this->project)->labeled('a_b')->create();
        AiKey::factory()->forProject($this->project)->labeled('axb')->create();

        expect(($this->search)('keys', 'prod'))->toHaveCount(1)
            ->and(collect(($this->search)('keys', 'a_b'))->pluck('uuid')->all())->toBe([$underscored->uuid])
            ->and(($this->search)('keys', '%'))->toBe([]);
    });

    it('finds a key without a provider, and takes * as any text', function () {
        $bare = AiKey::factory()->forProject($this->project)->labeled('night-batch-eu')->create(['provider' => null]);
        AiKey::factory()->forProject($this->project)->labeled('day-batch')->create();

        expect(collect(($this->search)('keys', 'night*eu'))->pluck('uuid')->all())->toBe([$bare->uuid])
            ->and(collect(($this->search)('keys'))->pluck('provider')->all())->toContain(null);
    });
});

describe('searching owners and actors', function () {
    it('finds owners from calls and from keys once each, the most lately used first, within 90 days of calls', function () {
        ($this->daily)('owner', 'tenant', '1', '2026-11-17');
        ($this->daily)('owner', 'tenant', '2', '2026-11-10');
        ($this->daily)('owner', 'tenant', '2', '2026-11-12');
        ($this->daily)('owner', 'tenant', '3', '2026-08-01');
        AiKey::factory()->forProject($this->project)->ownedBy('tenant', 2)->lastSeen(now()->subDays(20))->create();
        AiKey::factory()->forProject($this->project)->ownedBy('tenant', 4)->lastSeen(now())->create();

        expect(($this->search)('owners'))->toBe([
            ['type' => 'tenant', 'id' => '4'],
            ['type' => 'tenant', 'id' => '1'],
            ['type' => 'tenant', 'id' => '2'],
        ]);
    });

    it('gives back the latest 20 per type, so a busy type hides no other, also when searching', function (string $kind, string $column) {
        foreach (range(1, 100) as $id) {
            ($this->daily)($column, 'user', (string) $id, '2026-11-18');
        }

        foreach (range(1, 3) as $id) {
            ($this->daily)($column, 'team', (string) $id, '2026-10-01');
        }

        expect(collect(($this->search)($kind))->countBy('type')->all())->toBe(['user' => 20, 'team' => 3])
            ->and(collect(($this->search)($kind, '1'))->countBy('type')->all())->toBe(['user' => 20, 'team' => 1]);
    })->with([
        'owners' => ['owners', 'owner'],
        'actors' => ['actors', 'actor'],
    ]);

    it('gives back the 25 types used most lately', function () {
        foreach (range(1, AiTargetSearchQuery::GROUPS + 1) as $day) {
            ($this->daily)('actor', 'type'.$day, '1', Carbon::parse('2026-10-01')->addDays($day)->toDateString());
        }

        expect(collect(($this->search)('actors'))->pluck('type')->all())->toHaveCount(AiTargetSearchQuery::GROUPS)
            ->not->toContain('type1');
    });

    it('never gives another project\'s, and keeps a type holding a colon whole', function () {
        AiUsageDaily::factory()->byActor('user', 1)->create();
        ($this->daily)('actor', 'urn:user', '7', '2026-11-18');

        expect(($this->search)('actors'))->toBe([['type' => 'urn:user', 'id' => '7']]);
    });
});

describe('who may search', function () {
    it('refuses someone outside the team, a kind it does not know, and a search over 100 characters', function () {
        $this->actingAs(User::factory()->create())
            ->getJson(route('ai.targets.search', ['project' => $this->project, 'kind' => 'keys']))
            ->assertForbidden();
        $this->actingAs($this->user)
            ->getJson(route('ai.targets.search', ['project' => $this->project, 'kind' => 'models']))
            ->assertUnprocessable();
        $this->actingAs($this->user)
            ->getJson(route('ai.targets.search', ['project' => $this->project, 'kind' => 'keys', 'q' => str_repeat('a', 101)]))
            ->assertUnprocessable();
    });
});
