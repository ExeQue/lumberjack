<?php

declare(strict_types=1);

namespace App\Domains\Live\Actions;

use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Live\Events\ProjectChanged;
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

        $this->send(new ProjectChanged($projectId, $kind));
    }

    public function issueUpdated(int $projectId, string $issueUuid): void
    {
        $this->send(new ProjectChanged($projectId, ChangeKind::Issues, $issueUuid));
    }

    /**
     * Live updates are a convenience: a Reverb outage must never fail ingest or a user action.
     */
    private function send(ProjectChanged $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
