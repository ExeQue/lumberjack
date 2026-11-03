<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Models\HealthCheck;

/**
 * Puts away reported checks the app stopped sending, e.g. after a slug was renamed or a
 * check removed. One that reports again comes back by itself.
 */
class ArchiveSilentHealthChecksAction
{
    public const int SILENT_DAYS = 7;

    public function execute(): int
    {
        return HealthCheck::query()
            ->whereNotArchived()
            ->whereReportedSilentSince(now()->subDays(self::SILENT_DAYS))
            ->update(['archived_at' => now()]);
    }
}
