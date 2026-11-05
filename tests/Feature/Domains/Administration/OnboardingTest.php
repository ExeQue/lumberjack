<?php

declare(strict_types=1);

use App\Domains\Administration\Actions\CompleteOnboardingAction;
use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Administration\Http\Controllers\OnboardingController;
use App\Domains\Administration\Http\Middleware\RedirectToOnboarding;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(OnboardingController::class, RedirectToOnboarding::class, CompleteOnboardingAction::class, NotificationPreferences::class);

beforeEach(function () {
    $this->user = User::factory()->notOnboarded()->inTeam()->create();
});

describe('who is sent to the onboarding', function () {
    it('sends a new user there once, and not after finishing or skipping', function () {
        $this->actingAs($this->user)->get(route('team.show'))->assertRedirect(route('onboarding.show'));
        $this->get(route('onboarding.show'))->assertOk();

        // Done and Skip both end it, and continue to the page the user was going to.
        $this->post(route('onboarding.complete'))->assertRedirect(route('team.show'));

        expect($this->user->fresh()->onboarded_at)->not->toBeNull();
        $this->get(route('team.show'))->assertOk();
    });

    it('lands on the projects page without a page to go back to', function () {
        $this->actingAs($this->user)->post(route('onboarding.complete'))->assertRedirect(route('projects.index'));
    });

    it('sends an Inertia visit there too', function () {
        $this->actingAs($this->user)->withHeader('X-Inertia', 'true')->get(route('projects.index'))->assertRedirect(route('onboarding.show'));
    });

    it('leaves the account settings, sign-in flows and kiosk pages alone', function (string $route, array $parameters) {
        $response = $this->actingAs($this->user)->get(route($route, $parameters));

        expect($response->headers->get('Location'))->not->toBe(route('onboarding.show'));
    })->with([
        'the profile' => ['profile.edit', []],
        'the notification settings' => ['notifications.edit', []],
        'the onboarding itself' => ['onboarding.show', ['step' => 2]],
        'an invitation' => ['invitations.show', ['token' => 'unknown']],
        'a TV' => ['tv', []],
        'pairing a TV' => ['pair', []],
    ]);

    it('lets a required password change come first', function () {
        $this->user->forceFill(['password_change_required' => true])->save();

        $this->actingAs($this->user)->get(route('projects.index'))->assertRedirect(route('security.edit'));
    });

    it('waits until the email address is verified', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->user)->get(route('projects.index'))->assertRedirect(route('verification.notice'));
    });

    it('leaves JSON requests and writes untouched', function () {
        $this->actingAs($this->user)->getJson(route('notifications.recent'))->assertOk();
        $this->from(route('profile.edit'))->put(route('notifications.update'), NotificationPreferences::DEFAULTS)->assertRedirect(route('profile.edit'));
    });

    it('does not send users who existed before it was added', function () {
        $existing = User::factory()->notOnboarded()->inTeam()->create();
        $migration = require database_path('migrations/2026_11_05_070600_add_onboarded_at_to_users_table.php');
        $migration->down();
        $migration->up();

        expect($existing->fresh()->onboarded_at)->not->toBeNull();
        $this->actingAs($existing->fresh())->get(route('team.show'))->assertOk();
    });
});

describe('the steps', function () {
    it('shows the user\'s own teams and projects, not a team an administrator visits', function () {
        $team = $this->user->currentTeam;
        Project::factory()->for($team)->create(['name' => 'Shop']);
        Project::factory()->for($team)->create(['name' => 'Api']);
        $this->user->forceFill(['is_admin' => true, 'current_team_id' => Team::factory()->create()->id])->save();

        $this->actingAs($this->user)->get(route('onboarding.show'))->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/show')
            ->where('step', 1)
            ->has('teams', 1)
            ->where('teams.0.name', $team->name)
            ->where('teams.0.projects', ['Api', 'Shop']));
    });

    it('shows no teams to a user who left them all', function () {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)->get(route('onboarding.show'))->assertInertia(fn (Assert $page) => $page->where('teams', []));
    });

    it('offers the presets with the current preferences, then this browser', function () {
        $this->actingAs($this->user)->get(route('onboarding.show', ['step' => 2]))->assertInertia(fn (Assert $page) => $page
            ->where('notifications', NotificationPreferences::DEFAULTS)
            ->where('presets.balanced', NotificationPreferences::DEFAULTS));

        $this->get(route('onboarding.show', ['step' => 3]))->assertInertia(fn (Assert $page) => $page->has('push.devices'));
        $this->get('/onboarding/4')->assertNotFound();
    });

    it('can be opened again after it was done', function () {
        $this->user->forceFill(['onboarded_at' => now()->subWeek()])->save();

        $this->actingAs($this->user)->get(route('onboarding.show'))->assertOk();
        $this->post(route('onboarding.complete'))->assertRedirect(route('projects.index'));
    });
});

describe('the presets', function () {
    it('stores a preset through the settings route and comes back to the step', function (string $preset, array $expected) {
        $this->actingAs($this->user)
            ->from(route('onboarding.show', ['step' => 2]))
            ->put(route('notifications.update'), NotificationPreferences::presets()[$preset])
            ->assertRedirect(route('onboarding.show', ['step' => 2]));

        expect($this->user->fresh()->notification_preferences->toArray())->toBe($expected);
    })->with([
        'quiet: what concerns you, in app only' => ['quiet', [
            'watching' => ['mail' => false, 'toast' => true, 'push' => false],
            'mentions' => ['mail' => false, 'toast' => true, 'push' => false],
            'assignments' => ['mail' => false, 'toast' => true, 'push' => false],
            'new_issues' => ['mail' => false, 'toast' => false, 'push' => false],
            'health' => ['mail' => false, 'toast' => false, 'push' => false],
            'queue_backlogs' => ['mail' => false, 'toast' => false, 'push' => false],
            'scheduled_tasks' => ['mail' => false, 'toast' => false, 'push' => false],
            'agents' => ['mail' => false, 'toast' => false, 'push' => false],
            'releases' => ['mail' => false, 'toast' => false, 'push' => false],
        ]],
        'balanced: the defaults' => ['balanced', NotificationPreferences::DEFAULTS],
        'everything: in app and browser, mail as balanced' => ['everything', [
            'watching' => ['mail' => true, 'toast' => true, 'push' => true],
            'mentions' => ['mail' => true, 'toast' => true, 'push' => true],
            'assignments' => ['mail' => true, 'toast' => true, 'push' => true],
            'new_issues' => ['mail' => false, 'toast' => true, 'push' => true],
            'health' => ['mail' => false, 'toast' => true, 'push' => true],
            'queue_backlogs' => ['mail' => false, 'toast' => true, 'push' => true],
            'scheduled_tasks' => ['mail' => false, 'toast' => true, 'push' => true],
            'agents' => ['mail' => false, 'toast' => true, 'push' => true],
            'releases' => ['mail' => false, 'toast' => true, 'push' => true],
        ]],
    ]);
});
