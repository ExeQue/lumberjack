<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Http\Controllers;

use App\Domains\Connectors\Packagist\Exceptions\PackagistExceptionInterface;
use App\Domains\Connectors\Packagist\Queries\PackagistDashboardQuery;
use App\Domains\Connectors\Packagist\Support\PackagistClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A package as Packagist counts it: downloads over the period and per major version, its
 * popularity, its latest releases and the security advisories against it.
 */
class PackagistController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, PackagistDashboardQuery $query, ProjectPresenter $presenter, ?DataSourceConnection $dataSourceConnection = null): Response
    {
        Gate::authorize('view', $project);
        abort_if($dataSourceConnection?->isFor(ConnectionKind::Packagist) === false, 404);
        $connections = $project->packagistConnections()->get();
        $connection = $dataSourceConnection ?? $connections->first();
        $period = $this->period($request, Period::Month);

        // Packagist counts whole days, so it has no hour.
        if (! in_array($period, ConnectionKind::Packagist->periods(), true)) {
            $period = Period::Month;
        }

        $error = null;
        $data = null;

        if ($connection !== null) {
            try {
                $package = $query->package($connection);
                $data = [
                    'package' => ['name' => $package['name'], 'description' => $package['description'], 'repository' => $package['repository'], 'url' => PackagistClient::HOST.'/packages/'.$package['name']],
                    'summary' => $query->summary($connection, $period),
                    'series' => $query->series($connection, $period),
                    'majors' => $query->majors($connection, $period),
                    'releases' => $query->releases($connection),
                    'advisories' => $query->advisories($connection),
                ];
            } catch (PackagistExceptionInterface $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('connectors/packagist/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null],
            'connection' => $connection === null ? null : ['uuid' => $connection->uuid, 'name' => $connection->displayName()],
            // Every package the project reads, to switch between.
            'packages' => $connections->map(fn (DataSourceConnection $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName()])->all(),
            'error' => $error,
            ...($data ?? ['package' => null, 'summary' => null, 'series' => [], 'majors' => [], 'releases' => [], 'advisories' => []]),
        ]);
    }
}
