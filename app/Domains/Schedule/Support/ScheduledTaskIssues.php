<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Support;

use App\Domains\Issues\Models\Issue;

/**
 * A scheduled task that fails or is missed becomes an issue (RecordScheduleEventAction,
 * MarkMissedScheduledTasksAction), its occurrences carrying the task under "schedule"
 * in their context. Personal notifications tell of those as scheduled tasks rather
 * than as new issues.
 */
final class ScheduledTaskIssues
{
    public static function is(Issue $issue): bool
    {
        $context = $issue->occurrences()->latest('id')->first(['id', 'context'])?->context;

        return is_array($context) && array_key_exists('schedule', $context);
    }
}
