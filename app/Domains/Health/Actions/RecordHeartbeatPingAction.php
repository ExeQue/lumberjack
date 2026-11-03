<?php

declare(strict_types=1);

namespace App\Domains\Health\Actions;

use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Models\HealthCheck;

/**
 * A heartbeat check's secret URL was called: the job it watches is alive.
 */
class RecordHeartbeatPingAction
{
    public function __construct(
        private readonly RecordHealthResultAction $recordHealthResultAction,
    ) {
    }

    public function execute(string $token): ?HealthCheck
    {
        $check = HealthCheck::query()
            ->where('ping_token', $token)
            ->where('type', HealthCheckType::Heartbeat->value)
            ->whereNotArchived()
            ->first();

        if ($check === null) {
            return null;
        }

        $check->update(['last_ping_at' => now()]);
        $this->recordHealthResultAction->execute($check, new HealthResultData(HealthResultStatus::Ok, __('health.results.heartbeat_ok'), durationUs: 0, at: now()->toImmutable()));

        return $check;
    }
}
