<?php

declare(strict_types=1);

namespace App\Domains\Search\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Search\Http\Requests\SearchRequest;
use App\Domains\Search\Queries\ProjectSearchQuery;
use Illuminate\Http\JsonResponse;

/**
 * The command palette's search within a project.
 */
class SearchController
{
    public function __invoke(SearchRequest $request, Project $project, ProjectSearchQuery $query): JsonResponse
    {
        return response()->json([
            'results' => $query->get($project, trim($request->string('q')->toString())),
        ]);
    }
}
