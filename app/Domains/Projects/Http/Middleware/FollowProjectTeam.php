<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Middleware;

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Actions\SwitchTeamAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opening a project makes its team the current one, as picking it in the team switcher
 * would: the sidebar, breadcrumbs and team pages then match the project on screen. It
 * matters for a link into another team (an alert, a mention) and for an administrator.
 */
class FollowProjectTeam
{
    public function __construct(
        private readonly SwitchTeamAction $switchTeam,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        $user = $request->user();

        if ($project instanceof Project && $user !== null && $user->current_team_id !== $project->team_id && $user->can('view', $project)) {
            $this->switchTeam->execute($user, $project->team);
            $user->setRelation('currentTeam', $project->team);
        }

        return $next($request);
    }
}
