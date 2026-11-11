<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Eloquent\Builders\DataSourceWindowBuilder;
use App\Domains\DataSources\Jobs\FetchDataSourceWindow;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Shared\Enums\Period;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs every minute: queues the pulls that are due, one per source and period. Only periods
 * a screen asked for lately or a health check reads, so an app is never asked for what
 * nobody looks at.
 */
class DispatchDueDataSourceWindowsAction
{
    /** A screen that has not asked for this long is not being looked at. */
    public const int ACTIVE_MINUTES = 12;

    /** The most pulls queued for one app per minute, so that one app with many sources does not take every worker. */
    private const int MOST_PER_CONNECTION = 4;

    /** What nobody has asked for in this long is dropped. */
    private const int KEEP_HOURS = 24;

    /**
     * @return int pulls queued
     */
    public function execute(): int
    {
        DataSourceWindow::query()->where('requested_at', '<', now()->subHours(self::KEEP_HOURS))->delete();

        // A health check reading the period keeps it pulled however long the app caches.
        $due = fn (DataSourceWindowBuilder $q): DataSourceWindowBuilder => $q
            ->whereActive()
            ->where(fn (Builder $q) => $q->whereNull('next_fetch_at')->orWhere('next_fetch_at', '<=', now()))
            ->whereHas('source', fn (Builder $q) => $q->whereConnected()->whereNotArchived());

        $queued = 0;
        // What a health check reads first: rationed per app, a late pull fails a check but only ages a screen.
        $windows = DataSourceWindow::query()->select(['id', 'data_source_id', 'period'])->selectReadByHealthCheck()->tap($due)
            ->orderByRaw('read_by_check nulls last')->orderByRaw('next_fetch_at asc nulls first')->with('source:id,cache_seconds,data_source_connection_id')->get();
        $perConnection = [];

        foreach ($windows as $window) {
            $connection = (int) $window->source->data_source_connection_id;

            if (($perConnection[$connection] ?? 0) >= self::MOST_PER_CONNECTION) {
                continue;
            }

            $step = FetchDataSourceWindowAction::stepSeconds($window->source, Period::from($window->period));

            // Claimed only if still due, so two schedulers never both queue it.
            if (DataSourceWindow::query()->whereKey($window->id)->tap($due)->update(['next_fetch_at' => now()->startOfMinute()->addSeconds($step)]) === 1) {
                FetchDataSourceWindow::dispatch($window->id);
                $perConnection[$connection] = ($perConnection[$connection] ?? 0) + 1;
                $queued++;
            }
        }

        return $queued;
    }
}
