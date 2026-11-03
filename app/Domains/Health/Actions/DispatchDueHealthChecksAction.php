<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Jobs\RunHealthCheck;
use App\Domains\Health\Models\HealthCheck;

/**
 * Runs every minute: queues each check Lumberjack owns whose time has come, and moves
 * its next run on first, so a slow queue never runs one twice.
 */
class DispatchDueHealthChecksAction
{
    /**
     * @return int checks queued
     */
    public function execute(): int
    {
        $due = HealthCheck::query()->whereDue()->get();

        foreach ($due as $check) {
            $check->update(['next_run_at' => now()->startOfMinute()->addMinutes(max(1, (int) $check->interval_minutes))]);
            RunHealthCheck::dispatch($check);
        }

        return $due->count();
    }
}
