<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Actions\DeleteAccountAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Http\Controllers\Settings\ProfileController;
use App\Models\User;

covers(ProfileController::class, DeleteAccountAction::class);

describe('the profile settings', function () {
    test('profile page is displayed', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    });

    test('profile information can be updated', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        expect($user->name)->toBe('Test User');
        expect($user->email)->toBe('test@example.com');
        expect($user->email_verified_at)->toBeNull();
    });

    test('email verification status is unchanged when the email address is unchanged', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        expect($user->refresh()->email_verified_at)->not->toBeNull();
    });

    test('user can delete their account', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        expect($user->fresh())->toBeNull();
    });

    test('correct password must be provided to delete account', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        expect($user->fresh())->not->toBeNull();
    });
});

describe('deleting an account', function () {
    test('takes the teams only they are in, with their projects, along', function () {
        $user = User::factory()->inTeam()->create();
        $team = $user->currentTeam;
        $project = Project::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

        expect(Team::query()->whereKey($team->id)->exists())->toBeFalse()
            ->and(Project::query()->whereKey($project->id)->exists())->toBeFalse();
    });

    test('is refused while they own a team others are in', function () {
        $owner = User::factory()->inTeam()->create();
        User::factory()->inTeam($owner->currentTeam, TeamRole::Member)->create();

        $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');

        expect($owner->fresh())->not->toBeNull();
    });

    test('leaves a team they are only a member of to its owner', function () {
        $owner = User::factory()->inTeam()->create();
        $member = User::factory()->inTeam($owner->currentTeam, TeamRole::Member)->create();

        $this->actingAs($member)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();

        expect($member->fresh())->toBeNull()
            ->and(Team::query()->whereKey($owner->current_team_id)->exists())->toBeTrue();
    });
});
