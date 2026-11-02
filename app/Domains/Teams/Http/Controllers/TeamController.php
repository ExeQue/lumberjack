<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Controllers;

use App\Domains\Mcp\Enums\McpScope;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Actions\CreateTeamAction;
use App\Domains\Teams\Actions\SwitchTeamAction;
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
    public function show(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        // In no team yet: they join one by invitation.
        if ($team === null) {
            return Inertia::render('team/none');
        }

        $policies = $team->retentionPolicies()->whereNull('project_id')->get();

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
            'tokens' => $team->mcpTokens()->with(['projects:id,name,slug', 'user:id,name'])->latest()->get()->map(fn (McpToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'scope' => $t->scope->value,
                'projects' => $t->projects->pluck('name')->all(),
                'created_by' => $t->user?->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
            ])->all(),
            'scopes' => array_map(fn (McpScope $s): string => $s->value, McpScope::cases()),
            'retention' => collect(RetentionCategory::cases())->map(fn (RetentionCategory $c): array => [
                'category' => $c->value,
                'label' => $c->label(),
                'days' => $policies->first(fn (RetentionPolicy $p): bool => $p->category === $c)?->days,
                'default_days' => $c->defaultDays(),
            ])->all(),
            'mcp_url' => url('/mcp'),
        ]);
    }

    public function store(StoreTeamRequest $request, CreateTeamAction $action): RedirectResponse
    {
        $action->execute($request->user(), $request->string('name')->toString());

        return redirect()->route('projects.index');
    }

    public function switch(Request $request, Team $team, SwitchTeamAction $action): RedirectResponse
    {
        abort_unless($action->execute($request->user(), $team), 403);

        return redirect()->route('projects.index');
    }
}
