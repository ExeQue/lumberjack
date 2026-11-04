<?php

declare(strict_types=1);

namespace App\Domains\Performance\Http\Controllers;

use App\Domains\Performance\Enums\DeviceClass;
use App\Domains\Performance\Queries\BrowserQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BrowserController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, BrowserQuery $query, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request);
        $environment = $this->environment($request);
        // Like the period, a value the page does not offer falls back to the default.
        $device = DeviceClass::tryFrom((string) $request->query('device'));
        $sort = $request->query('sort') === 'lcp' ? 'lcp' : 'views';

        return Inertia::render('browser/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'device' => $device?->value, 'sort' => $sort],
            'has_vitals' => fn (): bool => $query->hasVitals($project->id),
            'summary' => fn (): array => $query->summary($project->id, $period, $environment, $device),
            'devices' => fn (): array => $query->devices($project->id, $period, $environment),
            'series' => fn (): array => $query->series($project->id, $period, $environment, $device),
            'pages' => fn (): array => $query->pages($project->id, $period, $environment, $device, $sort),
            'errors' => fn (): array => $query->errors($project->id, $period, $environment),
        ]);
    }
}
