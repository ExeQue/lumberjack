<?php

declare(strict_types=1);

namespace App\Domains\Releases\Http\Controllers;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, ReleaseHealthQuery $query, ProjectPresenter $projects, IssuePresenter $issues): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request);
        $environment = $this->environment($request);
        $selected = mb_substr((string) $request->query('release', ''), 0, 64);

        return Inertia::render('releases/index', [
            'project' => $projects->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'release' => $selected],
            'releases' => fn (): array => $query->get($project->id, $period, $environment),
            'newIssues' => fn (): ?array => $selected === '' ? null : Issue::query()
                ->whereKey($query->newIssueIds($project->id, $period, $environment, $selected))
                ->with('assignee')->orderByDesc('occurrences_count')->limit(25)->get()
                ->map(fn (Issue $issue): array => $issues->summary($issue))->all(),
        ]);
    }
}
