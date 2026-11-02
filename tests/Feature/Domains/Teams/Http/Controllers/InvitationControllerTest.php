<?php

declare(strict_types=1);

use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Http\Controllers\InvitationController;
use App\Domains\Teams\Notifications\TeamInvitationNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Teams\CreatesTeamMembers;

covers(InvitationController::class);

uses(CreatesTeamMembers::class);

describe('inviting', function () {
    it('mails an invitation when an admin invites', function () {
        Notification::fake();
        ['user' => $admin] = $this->memberWithProject(TeamRole::Admin);

        $this->actingAs($admin)->post(route('invitations.store'), ['email' => 'New@Example.test', 'role' => 'member'])->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(TeamInvitationNotification::class);
        expect($admin->currentTeam->invitations()->sole()->email)->toBe('new@example.test');
    });

    it('does not let a member invite', function () {
        ['user' => $member] = $this->memberWithProject(TeamRole::Member);

        $this->actingAs($member)->post(route('invitations.store'), ['email' => 'x@example.test', 'role' => 'member'])->assertForbidden();
    });
});

describe('accepting', function () {
    beforeEach(function () {
        Notification::fake();
        ['user' => $this->owner] = $this->memberWithProject();
        app(InviteMemberAction::class)->execute($this->owner->currentTeam, 'new@example.test', TeamRole::Member, $this->owner);

        Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification): bool {
            $this->token = $notification->token;

            return true;
        });
    });

    it('creates the account of a newcomer and joins the team', function () {
        $this->post(route('invitations.accept', $this->token), ['name' => 'New Person', 'password' => 'a-Long-password-123!', 'password_confirmation' => 'a-Long-password-123!'])
            ->assertRedirect(route('projects.index'));

        $user = User::query()->where('email', 'new@example.test')->sole();
        expect($user->roleIn($this->owner->currentTeam))->toBe(TeamRole::Member)
            ->and($user->current_team_id)->toBe($this->owner->current_team_id)
            // Only the team they were invited to: there are no personal teams.
            ->and($user->teams()->count())->toBe(1);
        $this->assertAuthenticatedAs($user);
    });

    it('lets the invited user accept while signed in', function () {
        $existing = User::factory()->inTeam()->create(['email' => 'New@Example.test']);

        $this->actingAs($existing)->post(route('invitations.accept', $this->token))->assertRedirect(route('projects.index'));

        expect($existing->belongsToTeam($this->owner->currentTeam))->toBeTrue();
    });

    it('does not let someone signed in as another account accept it', function () {
        $other = User::factory()->inTeam()->create();

        $this->actingAs($other)->get(route('invitations.show', $this->token))
            ->assertInertia(fn ($page) => $page->where('invitation.for_you', false)->where('invitation.signed_in_as', $other->email));
        $this->actingAs($other)->post(route('invitations.accept', $this->token))->assertSessionHasErrors('token');

        expect($other->fresh()->belongsToTeam($this->owner->currentTeam))->toBeFalse();
    });

    it('refuses an expired invitation', function () {
        $this->owner->currentTeam->invitations()->update(['expires_at' => now()->subMinute()]);

        $this->post(route('invitations.accept', $this->token), ['name' => 'X', 'password' => 'a-Long-password-123!', 'password_confirmation' => 'a-Long-password-123!'])
            ->assertSessionHasErrors('token');
    });
});

describe('resending', function () {
    it('mails a fresh link and retires the old one', function () {
        Notification::fake();
        ['user' => $admin] = $this->memberWithProject(TeamRole::Admin);
        $invitation = app(InviteMemberAction::class)->execute($admin->currentTeam, 'later@example.test', TeamRole::Member, $admin);
        $oldHash = $invitation->token_hash;

        $this->actingAs($admin)->post(route('invitations.resend', $invitation))->assertSessionHasNoErrors();

        expect($invitation->refresh()->token_hash)->not->toBe($oldHash);
        Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
    });
});
