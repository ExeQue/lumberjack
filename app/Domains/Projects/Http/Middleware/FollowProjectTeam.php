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
 *
 * Only a page someone opens switches: a top-level navigation, or an Inertia visit from
 * Lumberjack itself. An image or script another site points at the URL (Sec-Fetch-Dest
 * image, script, iframe, ...) does not, so it cannot change which team later actions go to.
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

        if ($project instanceof Project && $user !== null && $user->currentTeam?->id !== $project->team_id && self::isOpened($request) && $user->can('view', $project)) {
            $this->switchTeam->execute($user, $project->team);
            $user->setRelation('currentTeam', $project->team);
        }

        return $next($request);
    }

    /**
     * Browsers without Fetch Metadata send no Sec-Fetch-Dest; they are let through.
     */
    private static function isOpened(Request $request): bool
    {
        $dest = $request->header('Sec-Fetch-Dest');

        return $dest === null
            || $dest === 'document'
            || ($request->hasHeader('X-Inertia') && $request->header('Sec-Fetch-Site') === 'same-origin');
    }
}
