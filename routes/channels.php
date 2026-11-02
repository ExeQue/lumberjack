<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

Broadcast::channel('project.{projectId}', function (User $user, int $projectId): bool {
    $project = Project::query()->find($projectId);

    return $project !== null && $user->can('view', $project);
});

Broadcast::channel('team.{teamUuid}', function (User $user, string $teamUuid): bool {
    $team = Team::query()->where('uuid', $teamUuid)->first();

    return $team !== null && $user->belongsToTeam($team);
});
