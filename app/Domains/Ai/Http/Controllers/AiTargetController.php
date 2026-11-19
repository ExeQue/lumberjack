<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Http\Requests\SearchAiTargetsRequest;
use App\Domains\Ai\Queries\AiTargetSearchQuery;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\JsonResponse;

/**
 * The keys, key owners or actors the budget and price forms find for what is typed.
 */
class AiTargetController
{
    public function search(SearchAiTargetsRequest $request, Project $project, AiTargetSearchQuery $targets): JsonResponse
    {
        return response()->json(match ($request->kind()) {
            'keys' => $targets->keys($project, $request->term()),
            'owners' => $targets->owners($project, $request->term()),
            'actors' => $targets->actors($project, $request->term()),
        });
    }
}
