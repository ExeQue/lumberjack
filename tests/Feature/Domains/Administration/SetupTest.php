<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\SetupState;
use App\Domains\Administration\Http\Controllers\SetupController;
use App\Domains\Administration\Http\Middleware\EnsureSetupIsOpen;
use App\Domains\Administration\Http\Middleware\RedirectToSetup;
use App\Domains\Administration\Http\Middleware\RequirePasswordChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

covers(SetupController::class, EnsureSetupIsOpen::class, SetupState::class, RedirectToSetup::class, RequirePasswordChange::class);

describe('first-run setup', function () {
    test('an empty Lumberjack sends every page to setup', function () {
        $this->get(route('login'))->assertRedirect(route('setup'));
        $this->get(route('setup'))->assertOk();
    });

    test('the first user chooses their own credentials and becomes administrator', function () {
        $this->post(route('setup.store'), [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'a-long-Secret-1',
            'password_confirmation' => 'a-long-Secret-1',
        ])->assertRedirect(route('admin.teams.index'));

        $user = User::query()->sole();
        expect($user->email)->toBe('ada@example.com')
            ->and($user->is_admin)->toBeTrue()
            ->and($user->teams()->exists())->toBeFalse();
        $this->assertAuthenticatedAs($user);
        expect(Storage::disk('setup')->exists(RedirectToSetup::FLAG))->toBeTrue();
    });

    test('the administrator without a team can use the administration', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.teams.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.audit'))->assertOk();
        $this->actingAs($admin)->get(route('projects.index'))->assertRedirect(route('admin.teams.index'));
    });

    test('setup is gone once an active administrator exists, even with the token', function () {
        User::factory()->create(['is_admin' => true]);
        config(['app.setup_token' => 'tok-123']);

        $this->get(route('setup'))->assertNotFound();
        $this->post(route('setup.store'), [
            'name' => 'Mallory', 'email' => 'mallory@example.com',
            'password' => 'a-long-Secret-1', 'password_confirmation' => 'a-long-Secret-1',
        ])->assertNotFound();

        expect(User::query()->where('email', 'mallory@example.com')->exists())->toBeFalse();
    });

    test('with users but no administrator, setup is a recovery that only the token opens', function () {
        User::factory()->create();
        $payload = ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a-long-Secret-1', 'password_confirmation' => 'a-long-Secret-1'];

        // No token configured: nobody can claim this Lumberjack through the web.
        $this->get(route('setup'))->assertNotFound();
        $this->post(route('setup.store'), $payload)->assertNotFound();

        config(['app.setup_token' => 'tok-123']);
        $this->get(route('setup'))->assertOk();
        $this->post(route('setup.store'), [...$payload, 'token' => 'wrong'])->assertSessionHasErrors('token');
        $this->post(route('setup.store'), [...$payload, 'token' => 'tok-123'])->assertRedirect(route('admin.teams.index'));

        expect(User::query()->where('email', 'ada@example.com')->value('is_admin'))->toBeTrue();
    });

    test('a suspended administrator does not count as one', function () {
        User::factory()->create(['is_admin' => true, 'suspended_at' => now()]);
        config(['app.setup_token' => 'tok-123']);

        $this->get(route('setup'))->assertOk();
    });

    test('a set-up Lumberjack without a token answers /setup without asking the database', function () {
        User::factory()->create(['is_admin' => true]);
        RedirectToSetup::markComplete();
        DB::enableQueryLog();

        $this->get(route('setup'))->assertNotFound();

        expect(DB::getQueryLog())->toBe([]);
    });

    test('the last active administrator cannot delete their account', function () {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
        expect($admin->fresh())->not->toBeNull();

        User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasNoErrors();
        expect($admin->fresh())->toBeNull();
    });

    test('the setup token is required when configured', function () {
        config(['app.setup_token' => 'tok-123']);
        $payload = ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a-long-Secret-1', 'password_confirmation' => 'a-long-Secret-1'];

        $this->post(route('setup.store'), [...$payload, 'token' => 'wrong'])->assertSessionHasErrors('token');
        expect(User::query()->exists())->toBeFalse();

        $this->post(route('setup.store'), [...$payload, 'token' => 'tok-123'])->assertRedirect(route('admin.teams.index'));
    });

    test('registration does not exist: accounts come from setup or an invitation', function () {
        User::factory()->create();

        $this->get('/register')->assertNotFound();
    });
});

describe('required password change', function () {
    test('an administrator can make a user change their password before anything else', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.password-change', $user))->assertSessionHasNoErrors();
        expect($user->refresh()->password_change_required)->toBeTrue();

        $this->actingAs($user)->get(route('projects.index'))->assertRedirect(route('security.edit'));
    });

    test('changing the password lifts the requirement', function () {
        $user = User::factory()->create(['password_change_required' => true]);

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-long-Secret-1',
            'password_confirmation' => 'a-long-Secret-1',
        ])->assertSessionHasNoErrors();

        expect($user->refresh()->password_change_required)->toBeFalse();
    });

    test('a non-administrator cannot require it', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->put(route('admin.users.password-change', $other))->assertForbidden();
    });
});
