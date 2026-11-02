<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Controllers;

use App\Domains\Teams\Actions\ChangeMemberRoleAction;
use App\Domains\Teams\Actions\RemoveMemberAction;
use App\Domains\Teams\Actions\TransferOwnershipAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Exceptions\TeamExceptionInterface;
use App\Domains\Teams\Http\Requests\UpdateMemberRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MemberController
{
    public function update(UpdateMemberRequest $request, User $member, ChangeMemberRoleAction $action): RedirectResponse
    {
        try {
            $action->execute($request->user()->currentTeam, $member, TeamRole::from($request->string('role')->toString()));
        } catch (TeamExceptionInterface $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, User $member, RemoveMemberAction $action): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('administer', $team);

        try {
            $action->execute($team, $member);
        } catch (TeamExceptionInterface $e) {
            return back()->withErrors(['member' => $e->getMessage()]);
        }

        return back()->with('status', __('teams.member_removed'));
    }

    public function transfer(Request $request, User $member, TransferOwnershipAction $action): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('transfer', $team);
        $action->execute($team, $request->user(), $member);

        return back()->with('status', __('teams.ownership_transferred'));
    }
}
