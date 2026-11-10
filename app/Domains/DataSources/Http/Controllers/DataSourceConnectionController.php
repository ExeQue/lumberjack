<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Http\Controllers;

use App\Domains\Connectors\Enums\Connector;
use App\Domains\DataSources\Actions\ChangeDataSourceConnectionUrlAction;
use App\Domains\DataSources\Actions\CreateDataSourceConnectionAction;
use App\Domains\DataSources\Actions\RotatePullTokenAction;
use App\Domains\DataSources\Enums\ConnectionKind;
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

        return back()->with('secret', ['label' => __('data_sources.pull_token'), 'value' => $action->execute($project, $request->string('environment')->toString(), $request->string('url')->toString(), $request->string('timezone', 'UTC')->toString())]);
    }

    public function update(UpdateDataSourceConnectionRequest $request, Project $project, DataSourceConnection $dataSourceConnection, ChangeDataSourceConnectionUrlAction $action): RedirectResponse
    {
        $this->authorizeFor($project, $dataSourceConnection);
        // A service's address comes with its credentials, changed on the service's page.
        abort_unless($dataSourceConnection->isFor(ConnectionKind::App), 404);
        $action->execute($dataSourceConnection, $request->string('url')->toString(), $request->input('timezone'));

        return back()->with('status', __('data_sources.connection_updated', ['environment' => $dataSourceConnection->displayName()]));
    }

    public function rotate(Project $project, DataSourceConnection $dataSourceConnection, RotatePullTokenAction $action): RedirectResponse
    {
        $this->authorizeFor($project, $dataSourceConnection);
        abort_unless($dataSourceConnection->isFor(ConnectionKind::App), 404);

        return back()->with('secret', ['label' => __('data_sources.pull_token'), 'value' => $action->execute($dataSourceConnection)]);
    }

    public function destroy(Project $project, DataSourceConnection $dataSourceConnection): RedirectResponse
    {
        $this->authorizeFor($project, $dataSourceConnection);
        $dataSourceConnection->delete();
        $status = __('data_sources.connection_removed', ['environment' => $dataSourceConnection->displayName()]);
        $connector = Connector::forKind($dataSourceConnection->kind);

        // Removed from its own page, which is gone with it: on to the service's first one.
        if ($connector !== null && str_contains(url()->previous(), $dataSourceConnection->uuid)) {
            return redirect($connector->url($project))->with('status', $status);
        }

        return back()->with('status', $status);
    }

    private function authorizeFor(Project $project, DataSourceConnection $connection): void
    {
        abort_unless($connection->project_id === $project->id, 404);
        Gate::authorize('update', $connection);
    }
}
