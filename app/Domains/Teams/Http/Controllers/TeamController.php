<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Controllers;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Presenters\McpTokenPresenter;
use App\Domains\Teams\Actions\CreateTeamAction;
use App\Domains\Teams\Actions\SwitchTeamAction;
use App\Domains\Teams\Http\Middleware\UseTabTeam;
use App\Domains\Teams\Http\Requests\StoreTeamRequest;
use App\Domains\Teams\Models\Team;
use App\Domains\Teams\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController
{
    public function __construct(private readonly McpTokenPresenter $tokens)
    {
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        // In no team yet: they join one by invitation.
        if ($team === null) {
            return Inertia::render('team/none');
        }

        return Inertia::render('team/show', [
            'members' => $team->members()->orderBy('name')->get()->map(fn (User $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $m->pivot->role->value,
                'is_you' => $m->is($user),
            ])->all(),
            'invitations' => $team->invitations()->latest()->get()->map(fn (TeamInvitation $i): array => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->role->value,
                'expires_at' => $i->expires_at->toIso8601String(),
                'expired' => $i->isExpired(),
            ])->all(),
            // Each token's usability check reads its team, which is this one.
            'tokens' => $team->mcpTokens()->chaperone('team')->with(['projects:id,uuid,name,team_id', 'user:id,name,is_admin,suspended_at', 'user.teams:id'])->latest()->get()
                ->map(fn (McpToken $t): array => $this->tokens->token($t))->all(),
            'mcp_catalog' => $this->tokens->catalog(),
            'mcp_url' => url('/mcp'),
            'api_url' => url('/api/v1'),
            'can_create_team' => StoreTeamRequest::allowed($user),
        ]);
    }

    public function store(StoreTeamRequest $request, CreateTeamAction $action): RedirectResponse
    {
        $action->execute($request->user(), $request->string('name')->toString());

        return redirect()->route('projects.index')->with(UseTabTeam::SWITCHED, true);
    }

    public function switch(Request $request, Team $team, SwitchTeamAction $action): RedirectResponse
    {
        abort_unless($action->execute($request->user(), $team), 403);

        return redirect()->route('projects.index')->with(UseTabTeam::SWITCHED, true);
    }
}
