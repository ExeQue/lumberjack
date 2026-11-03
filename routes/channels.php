<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Broadcasting\TvPairingChannel;
use App\Domains\Screens\Support\TvViewer;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{uuid}', fn (User $user, string $uuid): bool => $user->uuid === $uuid);

// A paired display (the "tv" guard) hears its own team's projects, so its screen updates live.
Broadcast::channel('project.{projectUuid}', function (mixed $viewer, string $projectUuid): bool {
    $project = Project::query()->where('uuid', $projectUuid)->first();

    if ($project === null) {
        return false;
    }

    return $viewer instanceof TvViewer ? $viewer->seesTeam($project->team_id) : $viewer instanceof User && $viewer->can('view', $project);
}, ['guards' => ['web', 'tv']]);

Broadcast::channel('team.{teamUuid}', function (User $user, string $teamUuid): bool {
    $team = Team::query()->where('uuid', $teamUuid)->first();

    return $team !== null && $user->belongsToTeam($team);
});

Broadcast::channel('screen.{screen}', ScreenChannel::class, ['guards' => ['web', 'tv']]);
Broadcast::channel('display.{display}', DisplayChannel::class, ['guards' => ['tv']]);
Broadcast::channel('tv-pairing.{uuid}', TvPairingChannel::class, ['guards' => ['tv']]);
