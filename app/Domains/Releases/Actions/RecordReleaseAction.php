<?php

declare(strict_types=1);

namespace App\Domains\Releases\Actions;

use App\Domains\Notifications\Actions\NotifyProjectMembersAction;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\ReleaseNotification;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Writes down a release the first time a project's server reports it, and tells the
 * members who want it. A project's very first release is written down quietly: it is
 * whatever was running when this started counting, not a deploy.
 */
class RecordReleaseAction
{
    /** Every batch carries its release; the cache keeps that from costing a query each. */
    private const int REMEMBER_SECONDS = 86_400;

    public function __construct(
        private readonly NotifyProjectMembersAction $notifyProjectMembersAction,
    ) {
    }

    public function execute(Project $project, string $release): void
    {
        if (! Cache::add('release-seen:'.$project->id.':'.hash('xxh128', $release), true, self::REMEMBER_SECONDS)) {
            return;
        }

        $first = DB::table('project_releases')->where('project_id', $project->id)->doesntExist();
        $inserted = DB::table('project_releases')->insertOrIgnore(['project_id' => $project->id, 'release' => $release, 'first_seen_at' => now()]);

        if ($inserted === 0 || $first) {
            return;
        }

        $this->notifyProjectMembersAction->execute($project, new ReleaseNotification(PushMessage::about(
            $project,
            (string) __('notifications.push.release', ['project' => $project->name]),
            (string) __('notifications.push.release_body', ['release' => $release]),
            route('releases.index', $project),
            'release-'.$project->uuid,
        )));
    }
}
