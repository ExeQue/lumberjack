<?php

declare(strict_types=1);

namespace App\Domains\Health\Http\Controllers;

use App\Domains\Health\Actions\RecordHeartbeatPingAction;
use Illuminate\Http\JsonResponse;

/**
 * The secret URL a heartbeat check gives out; GET or POST, no body needed.
 */
class HealthPingController
{
    public function __invoke(string $token, RecordHeartbeatPingAction $action): JsonResponse
    {
        return $action->execute($token) !== null
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false], 404);
    }
}
