<?php

declare(strict_types=1);

namespace App\Domains\Health\Http\Controllers;

use App\Domains\Health\Http\Requests\HealthStatesRequest;
use App\Domains\Health\Queries\HealthChecksQuery;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/health: where the project's reported checks stand, for the app itself.
 * The same answer the agent's heartbeat gets, for apps that ask without the agent.
 */
class HealthStatesController
{
    public function __invoke(HealthStatesRequest $request, HealthChecksQuery $query): JsonResponse
    {
        $states = $query->states($request->project(), $request->environment());
        $slug = $request->slug();

        if ($slug !== null) {
            $states['checks'] = array_values(array_filter($states['checks'], fn (array $check): bool => $check['slug'] === $slug));
        }

        return response()->json($states);
    }
}
