<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\Projects\Models\Project;

/**
 * Gives one of a project's queues its own backlog thresholds, or mutes it. Every field
 * left empty and not muted means the queue uses the rule's again, so nothing is kept.
 */
class SetQueueAlertThresholdAction
{
    public function execute(Project $project, string $connection, string $queue, ?int $size, ?int $oldestMinutes, ?int $sustainedMinutes, bool $muted): ?QueueAlertThreshold
    {
        $key = ['project_id' => $project->id, 'connection' => $connection, 'queue' => $queue];

        if ($size === null && $oldestMinutes === null && $sustainedMinutes === null && ! $muted) {
            QueueAlertThreshold::query()->where($key)->delete();

            return null;
        }

        return QueueAlertThreshold::query()->updateOrCreate($key, [
            'size' => $size,
            'oldest_minutes' => $oldestMinutes,
            'sustained_minutes' => $sustainedMinutes,
            'muted' => $muted,
        ]);
    }
}
