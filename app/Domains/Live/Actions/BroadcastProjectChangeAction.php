<?php

declare(strict_types=1);

namespace App\Domains\Live\Actions;

use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Live\Events\ProjectChanged;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BroadcastProjectChangeAction
{
    /**
     * At most one broadcast per project and kind per second; a busy project
     * would otherwise send one per ingested batch.
     */
    public function execute(int $projectId, ChangeKind $kind): void
    {
        if (! Cache::add("live:{$projectId}:{$kind->value}", 1, 1)) {
            return;
        }

        $this->send($projectId, fn (string $uuid): ProjectChanged => new ProjectChanged($uuid, $kind));
    }

    public function issueUpdated(int $projectId, string $issueUuid): void
    {
        $this->send($projectId, fn (string $uuid): ProjectChanged => new ProjectChanged($uuid, ChangeKind::Issues, $issueUuid));
    }

    /**
     * Live updates are a convenience: a Reverb outage must never fail ingest or a user action.
     */
    /**
     * @param  Closure(string): ProjectChanged  $event  the event for the project's uuid, which names its channel
     */
    private function send(int $projectId, Closure $event): void
    {
        try {
            $uuid = Project::query()->whereKey($projectId)->value('uuid');

            if (is_string($uuid)) {
                broadcast($event($uuid));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
