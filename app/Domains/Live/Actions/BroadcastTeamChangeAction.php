<?php

declare(strict_types=1);

namespace App\Domains\Live\Actions;

use App\Domains\Live\Enums\TeamChangeKind;
use App\Domains\Live\Events\TeamChanged;
use App\Domains\Teams\Models\Team;

use function Illuminate\Support\defer;

use Throwable;

class BroadcastTeamChangeAction
{
    /**
     * Sent once the request, job or command is done, and once per team and kind however
     * many rows it changed: saving retention writes a policy per category. The tab whose
     * request made the change already shows it, so it is left out.
     */
    public function execute(int $teamId, TeamChangeKind $kind): void
    {
        defer(fn () => $this->send(Team::query()->whereKey($teamId)->value('uuid'), $kind), "live:team:{$teamId}:{$kind->value}");
    }

    /**
     * An application-wide setting every team inherits changed.
     * ponytail: one broadcast per team; a shared channel if teams ever number in the thousands.
     */
    public function everyTeam(TeamChangeKind $kind): void
    {
        defer(fn () => Team::query()->pluck('uuid')->each(fn (string $uuid) => $this->send($uuid, $kind)), "live:teams:{$kind->value}");
    }

    /** Live updates are a convenience: a Reverb outage must never fail what caused them. */
    private function send(mixed $teamUuid, TeamChangeKind $kind): void
    {
        if (! is_string($teamUuid)) {
            return;
        }

        try {
            broadcast(new TeamChanged($teamUuid, $kind))->toOthers();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
