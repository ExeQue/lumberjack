<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Http\Controllers;

use App\Domains\Connectors\MobilePay\Exceptions\MobilePayExceptionInterface;
use App\Domains\Connectors\MobilePay\Queries\MobilePayDashboardQuery;
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
 * A project's Vipps MobilePay ledger: revenue, transactions, refunds, fees and payouts over
 * the period against the period before, and the revenue of each day.
 */
class MobilePayController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, MobilePayDashboardQuery $query, ProjectPresenter $presenter, ?DataSourceConnection $dataSourceConnection = null): Response
    {
        Gate::authorize('view', $project);
        abort_if($dataSourceConnection?->isFor(ConnectionKind::MobilePay) === false, 404);
        $connections = $project->mobilePayConnections()->get();
        $connection = $dataSourceConnection ?? $connections->first();
        $period = $this->period($request, Period::Week);

        // The ledger is booked per day, so it has no hour.
        if (! in_array($period, ConnectionKind::MobilePay->periods(), true)) {
            $period = Period::Week;
        }
        $error = null;
        $summary = null;
        $series = [];

        if ($connection !== null) {
            try {
                $summary = $query->summary($connection, $period);
                $series = $query->series($connection, $period);
            } catch (MobilePayExceptionInterface $e) {
                $error = $e->getMessage();
            } catch (DecryptException) {
                $error = __('connectors.mobilepay.errors.secret_unreadable');
            }
        }

        return Inertia::render('connectors/mobilepay/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null],
            'connection' => $connection === null ? null : [
                'uuid' => $connection->uuid,
                'name' => $connection->displayName(),
                'timezone' => $connection->timezone,
            ],
            // Every ledger the project reads, to switch between.
            'ledgers' => $connections->map(fn (DataSourceConnection $c): array => ['uuid' => $c->uuid, 'name' => $c->displayName()])->all(),
            'error' => $error,
            'summary' => $summary,
            'series' => $series,
        ]);
    }
}
