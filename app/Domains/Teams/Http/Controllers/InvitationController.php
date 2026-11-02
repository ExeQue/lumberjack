<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Controllers;

use App\Domains\Teams\Actions\AcceptInvitationAction;
use App\Domains\Teams\Actions\CreateUserAction;
use App\Domains\Teams\Actions\FindInvitationAction;
use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Exceptions\TeamExceptionInterface;
use App\Domains\Teams\Http\Requests\AcceptInvitationRequest;
use App\Domains\Teams\Http\Requests\InviteMemberRequest;
use App\Domains\Teams\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController
{
    public function store(InviteMemberRequest $request, InviteMemberAction $action): RedirectResponse
    {
        $action->execute($request->user()->currentTeam, $request->string('email')->toString(), TeamRole::from($request->string('role')->toString()), $request->user());

        return back()->with('status', __('teams.invitation_sent'));
    }

    public function resend(Request $request, TeamInvitation $invitation, InviteMemberAction $action): RedirectResponse
    {
        Gate::authorize('administer', $invitation->team);

        // A fresh token and expiry; the old link stops working.
        $action->execute($invitation->team, $invitation->email, $invitation->role, $request->user());

        return back()->with('status', __('teams.invitation_sent'));
    }

    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        Gate::authorize('administer', $invitation->team);
        $invitation->delete();

        return back();
    }

    public function show(Request $request, string $token, FindInvitationAction $find): Response
    {
        try {
            $invitation = $find->execute($token);
        } catch (TeamExceptionInterface) {
            return Inertia::render('invitations/show', ['invitation' => null, 'token' => $token]);
        }

        return Inertia::render('invitations/show', [
            'token' => $token,
            'invitation' => [
                'team' => $invitation->team->name,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'has_account' => User::query()->where('email', $invitation->email)->exists(),
                // Signed in as someone else: they are offered to sign out, not to accept.
                'signed_in_as' => $request->user()?->email,
                'for_you' => $request->user() === null || $this->isFor($invitation, $request->user()),
            ],
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token, FindInvitationAction $find, AcceptInvitationAction $accept, CreateUserAction $createUser): RedirectResponse
    {
        try {
            $invitation = $find->execute($token);
        } catch (TeamExceptionInterface $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        $user = $request->user();

        // An invitation is for its email address: a forwarded link does not let someone
        // else join, and nobody joins a team by clicking while signed in as another account.
        if ($user !== null && ! $this->isFor($invitation, $user)) {
            return back()->withErrors(['token' => __('teams.invitation_wrong_account', ['email' => $invitation->email])]);
        }

        if ($user === null) {
            if (User::query()->where('email', $invitation->email)->exists()) {
                return redirect()->guest(route('login'));
            }

            $user = $createUser->execute($request->string('name')->toString(), $invitation->email, $request->string('password')->toString());
            Auth::login($user);
        }

        $accept->execute($token, $user);

        return redirect()->route('projects.index');
    }

    private function isFor(TeamInvitation $invitation, User $user): bool
    {
        return mb_strtolower($user->email) === mb_strtolower($invitation->email);
    }
}
