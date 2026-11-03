<?php

declare(strict_types=1);

namespace App\Domains\Live\Actions;

use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Live\Events\ProjectChanged;
use App\Domains\Live\Jobs\BroadcastTrailingProjectChange;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BroadcastProjectChangeAction
{
    /** Seconds between broadcasts of one kind for one project, unless the kind says otherwise. */
    public const int WINDOW_SECONDS = 1;

    /** Seconds between "this issue got another occurrence" broadcasts of one issue. */
    public const int ISSUE_WINDOW_SECONDS = 2;

    /** @var array<int, string|null> project uuids by id; a uuid never changes */
    private array $uuids = [];

    /**
     * Seconds between broadcasts of one kind. Performance changes with every request a
     * busy project serves, and each broadcast makes every open dashboard recompute its
     * statistics, so it waits longest.
     */
    public static function window(ChangeKind $kind): int
    {
        return match ($kind) {
            ChangeKind::Performance => 10,
            ChangeKind::Logs => 2,
            default => self::WINDOW_SECONDS,
        };
    }

    /**
     * At most one broadcast per project and kind per window; a busy project would
     * otherwise send one per ingested batch. A change inside the window is not dropped:
     * one more broadcast follows when the window ends, so the page sees the last of a
     * burst (the first of five health results would otherwise be the only one shown).
     */
    public function execute(int $projectId, ChangeKind $kind, ?string $projectUuid = null): void
    {
        $this->throttled($projectId, $kind, null, self::window($kind), $projectUuid);
    }

    /**
     * An issue got another occurrence: its own page reloads, throttled per issue like
     * the kinds are per project.
     */
    public function issueTouched(int $projectId, string $issueUuid, ?string $projectUuid = null): void
    {
        $this->throttled($projectId, ChangeKind::Issues, $issueUuid, self::ISSUE_WINDOW_SECONDS, $projectUuid);
    }

    /**
     * The broadcast at the end of a window, sent whatever the throttle says.
     */
    public function trailing(int $projectId, ChangeKind $kind, ?string $issueUuid = null): void
    {
        $this->send($projectId, $kind, $issueUuid);
    }

    public function issueUpdated(int $projectId, string $issueUuid): void
    {
        $this->send($projectId, ChangeKind::Issues, $issueUuid);
    }

    private function throttled(int $projectId, ChangeKind $kind, ?string $issueUuid, int $window, ?string $projectUuid): void
    {
        if ($projectUuid !== null) {
            $this->uuids[$projectId] = $projectUuid;
        }

        $key = $issueUuid !== null ? "live:issue:{$issueUuid}" : "live:{$projectId}:{$kind->value}";

        if (Cache::add($key, 1, $window)) {
            $this->send($projectId, $kind, $issueUuid);

            return;
        }

        if (Cache::add("{$key}:trailing", 1, $window)) {
            BroadcastTrailingProjectChange::dispatch($projectId, $kind, $issueUuid)->delay(now()->addSeconds($window));
        }
    }

    /**
     * Live updates are a convenience: a Reverb outage must never fail ingest or a user action.
     */
    private function send(int $projectId, ChangeKind $kind, ?string $issueUuid): void
    {
        try {
            if (! array_key_exists($projectId, $this->uuids)) {
                $uuid = Project::query()->whereKey($projectId)->value('uuid');
                $this->uuids[$projectId] = is_string($uuid) ? $uuid : null;
            }

            $uuid = $this->uuids[$projectId];

            if ($uuid !== null) {
                broadcast(new ProjectChanged($uuid, $kind, $issueUuid));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
