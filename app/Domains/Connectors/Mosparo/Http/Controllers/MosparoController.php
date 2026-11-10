<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Http\Controllers;

use App\Domains\Connectors\Mosparo\Exceptions\MosparoExceptionInterface;
use App\Domains\Connectors\Mosparo\Queries\MosparoDashboardQuery;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's form submissions as mosparo judged them: valid and spam over the period,
 * the requests it delayed or blocked, and every day mosparo still keeps.
 */
class MosparoController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, MosparoDashboardQuery $query, ProjectPresenter $presenter, ?DataSourceConnection $dataSourceConnection = null): Response
    {
        Gate::authorize('view', $project);
        abort_if($dataSourceConnection?->isFor(ConnectionKind::Mosparo) === false, 404);
        $connections = $project->mosparoConnections()->get();
        $connection = $dataSourceConnection ?? $connections->first();
        $period = $this->period($request, Period::Week);

        // mosparo counts whole days, so it has no hour.
        if (! in_array($period, ConnectionKind::Mosparo->periods(), true)) {
            $period = Period::Week;
        }
        $error = null;
        $summary = null;
        $series = [];

        if ($connection !== null) {
            try {
                $summary = $query->summary($connection, $period);
                $series = $query->series($connection, $period);
            } catch (MosparoExceptionInterface $e) {
                $error = $e->getMessage();
            } catch (DecryptException) {
                $error = __('connectors.mosparo.errors.secret_unreadable');
            }
        }

        return Inertia::render('connectors/mosparo/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null],
            'connection' => $connection === null ? null : [
                'uuid' => $connection->uuid,
                'name' => $connection->displayName(),
                'instance' => $connection->url,
                'timezone' => $connection->timezone,
            ],
            // Every mosparo project the project reads, to switch between.
            'projects' => $connections->map(fn (DataSourceConnection $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName()])->all(),
            'error' => $error,
            'summary' => $summary,
            'series' => $series,
        ]);
    }
}
