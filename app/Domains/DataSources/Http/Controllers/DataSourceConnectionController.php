<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Controllers;

use App\Domains\DataSources\Actions\ChangeDataSourceConnectionUrlAction;
use App\Domains\DataSources\Actions\CreateDataSourceConnectionAction;
use App\Domains\DataSources\Actions\RotatePullTokenAction;
use App\Domains\DataSources\Http\Requests\SaveDataSourceConnectionRequest;
use App\Domains\DataSources\Http\Requests\UpdateDataSourceConnectionRequest;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The apps Lumberjack pulls data sources from. A pull token is only ever shown once.
 */
class DataSourceConnectionController
{
    public function store(SaveDataSourceConnectionRequest $request, Project $project, CreateDataSourceConnectionAction $action): RedirectResponse
    {
        Gate::authorize('update', $project);

        return back()->with('secret', ['label' => __('data_sources.pull_token'), 'value' => $action->execute($project, $request->string('environment')->toString(), $request->string('url')->toString())]);
    }

    public function update(UpdateDataSourceConnectionRequest $request, Project $project, DataSourceConnection $dataSourceConnection, ChangeDataSourceConnectionUrlAction $action): RedirectResponse
    {
        $this->authorizeFor($project, $dataSourceConnection);
        $action->execute($dataSourceConnection, $request->string('url')->toString());

        return back()->with('status', __('data_sources.connection_updated', ['environment' => $dataSourceConnection->environment]));
    }

    public function rotate(Project $project, DataSourceConnection $dataSourceConnection, RotatePullTokenAction $action): RedirectResponse
    {
        $this->authorizeFor($project, $dataSourceConnection);

        return back()->with('secret', ['label' => __('data_sources.pull_token'), 'value' => $action->execute($dataSourceConnection)]);
    }

    public function destroy(Project $project, DataSourceConnection $dataSourceConnection): RedirectResponse
    {
        $this->authorizeFor($project, $dataSourceConnection);
        $dataSourceConnection->delete();

        return back()->with('status', __('data_sources.connection_removed', ['environment' => $dataSourceConnection->environment]));
    }

    private function authorizeFor(Project $project, DataSourceConnection $connection): void
    {
        Gate::authorize('update', $project);
        abort_unless($connection->project_id === $project->id, 404);
    }
}
