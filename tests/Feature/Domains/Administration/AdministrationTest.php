<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminAuditController;
use App\Domains\Administration\Http\Controllers\AdminTeamController;
use App\Domains\Administration\Http\Controllers\AdminUserController;
use App\Domains\Administration\Http\Middleware\RedirectToSetup;
use App\Domains\Administration\Models\AdminAuditEntry;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Domains\Teams\Notifications\TeamInvitationNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

covers(AdminTeamController::class, AdminUserController::class, AdminAuditController::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
});

describe('system administration', function () {
    test('only administrators reach it', function () {
        $user = User::factory()->inTeam()->create();

        foreach ([route('admin.teams.index'), route('admin.users.index'), route('admin.audit')] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    });

    test('an administrator sees the projects of a team they are not in', function () {
        $project = Project::factory()->create();

        $this->actingAs($this->admin)->get(route('projects.show', $project->uuid))->assertOk();
    });

    test('an administrator creates a team for an existing user', function () {
        $owner = User::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.teams.store'), ['name' => 'Platform', 'owner' => $owner->email])->assertSessionHasNoErrors();

        expect($owner->teams()->where('name', 'Platform')->exists())->toBeTrue()
            ->and(AdminAuditEntry::query()->where('subject', 'Platform')->exists())->toBeTrue();
    });

    test('a team for someone without an account waits for them to accept ownership', function () {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.teams.store'), ['name' => 'Newco', 'owner' => 'new@example.com'])->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Newco')->sole();
        expect($team->members()->count())->toBe(0);

        $token = null;
        Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $n) use (&$token): bool {
            $token = $n->token;

            return true;
        });

        auth()->guard('web')->logout();
        $this->post(route('invitations.accept', $token), ['name' => 'New Owner', 'password' => 'a-Long-password-123!', 'password_confirmation' => 'a-Long-password-123!']);

        $owner = User::query()->where('email', 'new@example.com')->sole();
        expect($owner->roleIn($team))->toBe(TeamRole::Owner);
    });

    test('setup:clear forgets the flag', function () {
        RedirectToSetup::markComplete();

        $this->artisan('setup:clear')->assertSuccessful();

        expect(Storage::disk('setup')->exists(RedirectToSetup::FLAG))->toBeFalse();
    });

    test('deleting a team moves its members to another of their teams', function () {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $member = User::factory()->inTeam($other)->inTeam($team)->create(['current_team_id' => $team->id]);

        $this->actingAs($this->admin)->delete(route('admin.teams.destroy', $team->uuid))->assertSessionHasNoErrors();

        expect(Team::query()->whereKey($team->id)->exists())->toBeFalse()
            ->and($member->refresh()->current_team_id)->toBe($other->id);
    });

    test('suspending signs the user out and keeps them out', function () {
        $user = User::factory()->inTeam()->create();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->put(route('admin.users.suspend', $user), ['suspended' => true])->assertSessionHasNoErrors();

        expect($user->refresh()->isSuspended())->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
        $this->actingAs($user)->get(route('projects.index'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    });

    test('an administrator cannot suspend or demote themselves', function () {
        $this->actingAs($this->admin)->put(route('admin.users.suspend', $this->admin), ['suspended' => true])->assertSessionHasErrors('user');
        $this->actingAs($this->admin)->put(route('admin.users.admin', $this->admin), ['is_admin' => false])->assertSessionHasErrors('user');

        expect($this->admin->refresh()->is_admin)->toBeTrue()
            ->and($this->admin->isSuspended())->toBeFalse();
    });

    test('administration can be granted and revoked', function () {
        $user = User::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.users.admin', $user), ['is_admin' => true]);
        expect($user->refresh()->is_admin)->toBeTrue();

        $this->actingAs($this->admin)->put(route('admin.users.admin', $user), ['is_admin' => false]);
        expect($user->refresh()->is_admin)->toBeFalse();
    });
});
