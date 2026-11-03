<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Actions\SetAdminAction;
use App\Domains\Administration\Actions\SetSuspendedAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Exceptions\AdministrationExceptionInterface;
use App\Domains\Administration\Http\Requests\InviteUserRequest;
use App\Domains\Shared\Support\LikePattern;
use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Exceptions\TeamExceptionInterface;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController
{
    public const int PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->when($search !== '')->where(fn ($q) => $q->where('name', 'ilike', LikePattern::contains($search))->orWhere('email', 'ilike', LikePattern::contains($search)))
            ->with('teams:id,name')
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/users', [
            'filters' => ['search' => $search],
            'users' => $users->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'suspended' => $user->isSuspended(),
                'password_change_required' => $user->password_change_required,
                'teams' => $user->teams->pluck('name')->all(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]),
            'teams' => Team::query()->orderBy('name')->get(['uuid', 'name'])->map(fn (Team $t): array => ['uuid' => $t->uuid, 'name' => $t->name])->all(),
        ]);
    }

    public function suspend(Request $request, User $user, SetSuspendedAction $action): RedirectResponse
    {
        return $this->attempt(fn () => $action->execute($request->user(), $user, $request->boolean('suspended')));
    }

    public function admin(Request $request, User $user, SetAdminAction $action): RedirectResponse
    {
        return $this->attempt(fn () => $action->execute($request->user(), $user, $request->boolean('is_admin')));
    }

    /**
     * The user has to choose a new password before they can do anything else.
     */
    public function requirePasswordChange(Request $request, User $user, RecordAdminAuditAction $audit): RedirectResponse
    {
        $user->forceFill(['password_change_required' => true])->save();
        $audit->execute($request->user(), AdminAction::PasswordChangeRequired, $user->email);

        return back()->with('status', __('administration.password_change_requested', ['email' => $user->email]));
    }

    public function sendReset(Request $request, User $user, RecordAdminAuditAction $audit): RedirectResponse
    {
        Password::sendResetLink(['email' => $user->email]);
        $audit->execute($request->user(), AdminAction::PasswordResetSent, $user->email);

        return back()->with('status', __('administration.reset_sent', ['email' => $user->email]));
    }

    /**
     * Invitations are the only way into Lumberjack, for administrators too.
     */
    public function invite(InviteUserRequest $request, InviteMemberAction $invite, RecordAdminAuditAction $audit): RedirectResponse
    {
        $team = Team::query()->where('uuid', $request->string('team')->toString())->firstOrFail();
        $email = $request->string('email')->toString();

        try {
            $invite->execute($team, $email, TeamRole::from($request->string('role')->toString()), $request->user());
        } catch (TeamExceptionInterface $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        $audit->execute($request->user(), AdminAction::UserInvited, $email, ['team' => $team->name]);

        return back()->with('status', __('administration.invited', ['email' => $email]));
    }

    private function attempt(callable $change): RedirectResponse
    {
        try {
            $change();
        } catch (AdministrationExceptionInterface $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('status', __('administration.saved'));
    }
}
