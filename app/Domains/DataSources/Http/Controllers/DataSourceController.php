<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Controllers;

use App\Domains\DataSources\Actions\SaveDataSourceAction;
use App\Domains\DataSources\Http\Requests\SaveDataSourceRequest;
use App\Domains\DataSources\Jobs\FetchDataSource;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's external data sources (JSON or Prometheus text), which screen widgets read. Header values never
 * leave the server: the page gets their names only, and the URL without its query values.
 */
class DataSourceController
{
    /** How much of the latest body the page shows, to help write a JSONPath or selector. */
    public const int PREVIEW_BYTES = 4096;

    public function index(Request $request, Project $project, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('data-sources/index', [
            'project' => $presenter->header($project, $request->user()),
            'sources' => fn (): array => $project->dataSources()->orderBy('name')->get()->map(fn (DataSource $s): array => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'url' => $s->maskedUrl(),
                'format' => $s->format->value,
                'header_names' => $s->headerNames(),
                'interval_minutes' => $s->interval_minutes,
                'timeout_s' => $s->timeout_s,
                'last_fetched_at' => $s->last_fetched_at?->toIso8601String(),
                'last_ok_at' => $s->last_ok_at?->toIso8601String(),
                'last_status_code' => $s->last_status_code,
                'last_error' => $s->last_error,
                'preview' => $s->last_body !== null ? mb_strcut($s->last_body, 0, self::PREVIEW_BYTES) : null,
            ])->all(),
        ]);
    }

    public function store(SaveDataSourceRequest $request, Project $project, SaveDataSourceAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        $source = $action->execute($project, null, $request->validated());

        return back()->with('status', __('data_sources.saved', ['source' => $source->name]));
    }

    public function update(SaveDataSourceRequest $request, Project $project, DataSource $dataSource, SaveDataSourceAction $action): RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        $action->execute($project, $dataSource, $request->validated());

        return back()->with('status', __('data_sources.saved', ['source' => $dataSource->name]));
    }

    public function fetch(Project $project, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        FetchDataSource::dispatch($dataSource);

        return back()->with('status', __('data_sources.fetch_queued', ['source' => $dataSource->name]));
    }

    public function destroy(Project $project, DataSource $dataSource): RedirectResponse
    {
        Gate::authorize('update', $dataSource);

        $dataSource->delete();

        return back()->with('status', __('data_sources.deleted', ['source' => $dataSource->name]));
    }
}
