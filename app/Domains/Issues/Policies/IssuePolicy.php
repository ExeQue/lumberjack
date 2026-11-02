<?php

declare(strict_types=1);

namespace App\Domains\Issues\Policies;

use App\Domains\Issues\Models\Issue;
use App\Models\User;

class IssuePolicy
{
    public function view(User $user, Issue $issue): bool
    {
        return $user->belongsToTeam($issue->project->team);
    }

    /**
     * Resolve, ignore, assign, note and open a repo issue: every member may.
     */
    public function update(User $user, Issue $issue): bool
    {
        return $user->belongsToTeam($issue->project->team);
    }
}
