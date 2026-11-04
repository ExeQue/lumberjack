<?php

declare(strict_types=1);

namespace App\Domains\Teams\Http\Middleware;

use App\Domains\Teams\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each browser tab keeps its own team: the tab sends the one it shows in a header, and
 * for this request that is the user's current team. Nothing is saved; the team stored
 * on the user is where a new tab starts. A team the user may not open is ignored.
 */
class UseTabTeam
{
    public const string HEADER = 'X-Lumberjack-Team';

    /** Flashed when the user picks a team: the next page shows it, not the tab's old one. */
    public const string SWITCHED = 'team_switched';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $uuid = $request->header(self::HEADER);

        $switched = $request->hasSession() && $request->session()->get(self::SWITCHED) === true;

        if ($user !== null && ! $switched && is_string($uuid) && $uuid !== '' && $uuid !== $user->currentTeam?->uuid) {
            $team = Team::query()->where('uuid', $uuid)->first();

            if ($team !== null && ($user->belongsToTeam($team) || $user->isAdmin())) {
                $user->setRelation('currentTeam', $team);
            }
        }

        $response = $next($request);
        // The same address answers differently per tab.
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', '.self::HEADER, ', '));

        return $response;
    }
}
