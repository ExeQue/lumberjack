<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Models\HealthCheck;

/**
 * Deletes a check with its hosts, results and outages.
 */
class DeleteHealthCheckAction
{
    public function execute(HealthCheck $check): void
    {
        $check->delete();
    }
}
