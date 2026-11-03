<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('user.{uuid}', fn (User $user, string $uuid): bool => $user->uuid === $uuid);

Broadcast::channel('project.{projectUuid}', function (User $user, string $projectUuid): bool {
    $project = Project::query()->where('uuid', $projectUuid)->first();

    return $project !== null && $user->can('view', $project);
});

Broadcast::channel('team.{teamUuid}', function (User $user, string $teamUuid): bool {
    $team = Team::query()->where('uuid', $teamUuid)->first();

    return $team !== null && $user->belongsToTeam($team);
});
