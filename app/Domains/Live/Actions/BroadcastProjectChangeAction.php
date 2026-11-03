<?php

declare(strict_types=1);

namespace App\Domains\Live\Actions;

use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Live\Events\ProjectChanged;
use App\Domains\Live\Jobs\BroadcastTrailingProjectChange;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BroadcastProjectChangeAction
{
    /** Seconds between broadcasts of one kind for one project. */
    public const int WINDOW_SECONDS = 1;

    /**
     * At most one broadcast per project and kind per window; a busy project would
     * otherwise send one per ingested batch. A change inside the window is not dropped:
     * one more broadcast follows when the window ends, so the page sees the last of a
     * burst (the first of five health results would otherwise be the only one shown).
     */
    public function execute(int $projectId, ChangeKind $kind): void
    {
        if (Cache::add("live:{$projectId}:{$kind->value}", 1, self::WINDOW_SECONDS)) {
            $this->send($projectId, fn (string $uuid): ProjectChanged => new ProjectChanged($uuid, $kind));

            return;
        }

        if (Cache::add("live:{$projectId}:{$kind->value}:trailing", 1, self::WINDOW_SECONDS)) {
            BroadcastTrailingProjectChange::dispatch($projectId, $kind)->delay(now()->addSeconds(self::WINDOW_SECONDS));
        }
    }

    /**
     * The broadcast at the end of a window, sent whatever the throttle says.
     */
    public function trailing(int $projectId, ChangeKind $kind): void
    {
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
