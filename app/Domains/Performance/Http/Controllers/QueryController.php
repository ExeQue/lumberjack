<?php

declare(strict_types=1);

namespace App\Domains\Performance\Http\Controllers;

use App\Domains\Performance\Queries\QueryStatisticsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class QueryController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, QueryStatisticsQuery $query, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $sort = (string) $request->query('sort', 'total');
        $search = trim((string) $request->query('search', ''));
        $connection = (string) $request->query('connection', '');

        return Inertia::render('queries/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'sort' => $sort, 'search' => $search, 'connection' => $connection],
            'connections' => DB::table('query_runs')->where('project_id', $project->id)->where('occurred_at', '>=', now()->subDays(7))->distinct()->limit(20)->pluck('connection'),
            'stats' => fn (): array => $query->get($project->id, $this->period($request), $this->environment($request), $sort, $search, $connection),
        ]);
    }
}
