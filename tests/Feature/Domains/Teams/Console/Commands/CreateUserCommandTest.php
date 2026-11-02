<?php

declare(strict_types=1);

use App\Domains\Teams\Console\Commands\CreateUserCommand;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;

covers(CreateUserCommand::class);

describe('creating a user from the command line', function () {
    it('makes the first user an administrator, in no team', function () {
        $this->artisan('lumberjack:create-user', ['--name' => 'Ada', '--email' => 'ada@example.test', '--password' => 'secret-password'])->assertSuccessful();

        $user = User::query()->sole();
        expect($user->is_admin)->toBeTrue()
            ->and($user->teams()->count())->toBe(0);
    });

    it('makes them the owner of a new team when given one', function () {
        User::factory()->create(['is_admin' => true]);

        $this->artisan('lumberjack:create-user', ['--name' => 'Bo', '--email' => 'bo@example.test', '--password' => 'secret-password', '--team' => 'Platform'])->assertSuccessful();

        $user = User::query()->where('email', 'bo@example.test')->sole();
        expect($user->is_admin)->toBeFalse()
            ->and($user->currentTeam?->name)->toBe('Platform')
            ->and($user->roleIn($user->currentTeam))->toBe(TeamRole::Owner);
    });
});

describe('someone in no team', function () {
    it('is told to wait for an invitation instead of a 404', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.index'))->assertRedirect(route('team.show'));
        $this->actingAs($user)->get(route('team.show'))->assertOk()->assertInertia(fn ($page) => $page->component('team/none'));
    });
});
