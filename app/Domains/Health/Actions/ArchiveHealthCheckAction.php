<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Models\HealthCheck;

/**
 * Puts a check away, e.g. a reported slug that was renamed and will never report
 * again. It sends nothing and leaves the counts; a reported check that reports again
 * comes back by itself.
 */
class ArchiveHealthCheckAction
{
    public function __construct(
        private readonly WantDataSourcePeriodAction $wantDataSourcePeriodAction,
    ) {
    }

    public function execute(HealthCheck $check, bool $archived): HealthCheck
    {
        $check->update(['archived_at' => $archived ? now() : null]);

        // Back from the archive, a data source check waits for the next pull of its period.
        if (! $archived) {
            $this->wantDataSourcePeriodAction->execute($check);
        }

        return $check;
    }
}
