<?php

declare(strict_types=1);

namespace Tests\Support\Health;

use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use Carbon\CarbonImmutable;

trait CreatesDatabaseHealthResults
{
    /**
     * One passing result of a database check, carrying the given metrics.
     *
     * @param  array<string, int|float>  $metrics
     */
    public function createDatabaseResult(HealthCheck $check, array $metrics, ?CarbonImmutable $at = null, string $host = 'web-1'): HealthCheckResult
    {
        return app(RecordHealthResultAction::class)->execute(
            $check,
            new HealthResultData(HealthResultStatus::Ok, metrics: $metrics, host: $host, at: $at ?? now()->toImmutable()),
            announce: false,
        );
    }
}
