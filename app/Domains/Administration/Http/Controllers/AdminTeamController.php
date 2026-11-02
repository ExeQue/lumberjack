<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Actions\CreateTeamForOwnerAction;
use App\Domains\Administration\Actions\DeleteTeamAction;
use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Administration\Http\Requests\CreateTeamRequest;
use App\Domains\Teams\Actions\SwitchTeamAction;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminTeamController
{
    public function index(): Response
    {
        $teams = Team::query()->withCount(['members', 'projects'])->orderBy('name')->get();
        $events = DB::table('occurrences')
            ->join('projects', 'projects.id', '=', 'occurrences.project_id')
            ->where('occurrences.occurred_at', '>=', now()->subDay())
            ->selectRaw('projects.team_id, COUNT(*) AS c')->groupBy('projects.team_id')->pluck('c', 'team_id');
        $owners = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')
            ->where('team_user.role', TeamRole::Owner->value)->pluck('users.email', 'team_user.team_id');

        return Inertia::render('admin/teams', [
            'teams' => $teams->map(fn (Team $team): array => [
                'uuid' => $team->uuid,
                'name' => $team->name,
                'owner' => $owners[$team->id] ?? null,
                'members' => (int) $team->members_count,
                'projects' => (int) $team->projects_count,
                'errors_24h' => (int) ($events[$team->id] ?? 0),
                'created_at' => $team->created_at?->toIso8601String(),
            ])->all(),
            // ponytail: every user for the owner picker; a search endpoint if this ever reaches thousands.
            'users' => User::query()->orderBy('name')->get(['name', 'email'])->map(fn (User $u): array => ['name' => $u->name, 'email' => $u->email])->all(),
        ]);
    }

    public function store(CreateTeamRequest $request, CreateTeamForOwnerAction $create): RedirectResponse
    {
        $result = $create->execute($request->user(), $request->string('name')->toString(), $request->string('owner')->toString());

        return back()->with('status', __($result['invited'] ? 'administration.team_created_invited' : 'administration.team_created', [
            'name' => $result['team']->name,
            'email' => $request->string('owner')->toString(),
        ]));
    }

    /**
     * Makes the team the administrator's current one, so its own pages open with it.
     */
    public function open(Request $request, Team $team, SwitchTeamAction $switch, RecordAdminAuditAction $audit): RedirectResponse
    {
        $switch->execute($request->user(), $team);
        $audit->execute($request->user(), AdminAction::TeamOpened, $team->name);

        return redirect()->route('projects.index');
    }

    public function destroy(Request $request, Team $team, DeleteTeamAction $delete): RedirectResponse
    {
        $name = $team->name;
        $delete->execute($request->user(), $team);

        return back()->with('status', __('administration.team_deleted', ['name' => $name]));
    }
}
