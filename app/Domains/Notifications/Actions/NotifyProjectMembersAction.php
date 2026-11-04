<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Actions;

use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Something happened in a project: every member of its team who may view it gets the
 * notification, which goes out by the channels their preferences allow.
 */
class NotifyProjectMembersAction
{
    public function execute(Project $project, Notification $notification): void
    {
        $members = $project->team->members()->whereNotSuspended()->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $project));

        Notifications::send($members, $notification);
    }
}
