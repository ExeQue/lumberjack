<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Http\Controllers;

use App\Domains\Connectors\MobilePay\Exceptions\MobilePayExceptionInterface;
use App\Domains\Connectors\MobilePay\Queries\MobilePayDashboardQuery;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
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
 * A project's Vipps MobilePay ledger: revenue, transactions, refunds, fees, payouts, the
 * average order and the refund and fee rates over the period against the period before,
 * the balance, the revenue of each day, and the period's transactions, largest sales,
 * payouts, sales per hour and weekday and revenue per sales unit.
 */
class MobilePayController
{
    use ReadsDashboardFilters;

    /** Each list's rows at first, which is also how many each Load more adds, and the most it loads. */
    private const array LISTS = [
        'transactions' => [20, MobilePayClient::MAX_LIST],
        'largest' => [10, 100],
        'payouts' => [20, MobilePayClient::MAX_LIST],
    ];

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
        $details = null;

        // How many rows each list shows, as rows[list]=n, grown by its Load more.
        $rows = array_map(fn (array $list): int => $list[0], self::LISTS);

        foreach (array_intersect_key((array) $request->query('rows', []), self::LISTS) as $list => $n) {
            $rows[$list] = is_numeric($n) ? min(max((int) $n, self::LISTS[$list][0]), self::LISTS[$list][1]) : self::LISTS[$list][0];
        }

        if ($connection !== null) {
            try {
                $summary = $query->summary($connection, $period);
                $series = $query->series($connection, $period);
                $details = $query->details($connection, $period, $rows);
            } catch (MobilePayExceptionInterface $e) {
                $error = $e->getMessage();
            } catch (DecryptException) {
                $error = __('connectors.mobilepay.errors.secret_unreadable');
            }
        }

        return Inertia::render('connectors/mobilepay/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => ['period' => $period->value, 'environment' => null],
            'rows' => (object) array_filter($rows, fn (int $n, string $list): bool => $n > self::LISTS[$list][0], ARRAY_FILTER_USE_BOTH),
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
            'details' => $details,
        ]);
    }
}
