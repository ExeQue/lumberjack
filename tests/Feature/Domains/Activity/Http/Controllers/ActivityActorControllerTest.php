<?php

declare(strict_types=1);

use App\Domains\Activity\Http\Controllers\ActivityActorController;
use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;

covers(ActivityActorController::class);

beforeEach(function () {
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
    $this->other = Project::factory()->create();
    Activity::factory()->forProject($this->project)->byActor('user', 17)->count(2)->create();
    Activity::factory()->forProject($this->project)->byActor('user', 18)->create();
    Activity::factory()->forProject($this->other)->byActor('user', 17)->create();
    ActivityCount::factory()->forProject($this->project)->create(['count' => 3]);
});

describe('forgetting an actor from the app', function () {
    it('deletes the actor\'s events in the token\'s project only, and keeps the counts', function () {
        $this->deleteJson('/api/events/actor', ['type' => 'user', 'id' => '17'], ['Authorization' => 'Bearer lj_srv_test-token'])
            ->assertOk()
            ->assertJson(['deleted' => 2]);

        expect(Activity::query()->where('project_id', $this->project->id)->pluck('actor_id')->all())->toBe(['18'])
            ->and(Activity::query()->where('project_id', $this->other->id)->count())->toBe(1)
            ->and(ActivityCount::query()->sole()->count)->toBe(3);
    });

    it('needs the server token and an actor', function () {
        $this->deleteJson('/api/events/actor', ['type' => 'user', 'id' => '17'])->assertUnauthorized();
        $this->deleteJson('/api/events/actor', ['type' => 'user'], ['Authorization' => 'Bearer lj_srv_test-token'])->assertUnprocessable();

        expect(Activity::query()->count())->toBe(4);
    });
});

describe('forgetting an actor from the event page', function () {
    it('lets a team admin delete the actor\'s events', function () {
        $admin = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();

        $this->actingAs($admin)
            ->delete(route('events.actor.destroy', ['project' => $this->project]), ['type' => 'user', 'id' => '17'])
            ->assertRedirect()
            ->assertSessionHas('status');

        expect(Activity::query()->where('project_id', $this->project->id)->count())->toBe(1);
    });

    it('is closed to a member', function () {
        $member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();

        $this->actingAs($member)
            ->delete(route('events.actor.destroy', ['project' => $this->project]), ['type' => 'user', 'id' => '17'])
            ->assertForbidden();

        expect(Activity::query()->count())->toBe(4);
    });
});
