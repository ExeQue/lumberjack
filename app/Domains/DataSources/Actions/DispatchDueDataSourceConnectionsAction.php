<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Jobs\SyncDataSourceConnection;
use App\Domains\DataSources\Models\DataSourceConnection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs every minute: queues asking each app that is due which sources it has.
 */
class DispatchDueDataSourceConnectionsAction
{
    public const int EVERY_MINUTES = 5;

    /**
     * @return int connections queued
     */
    public function execute(): int
    {
        $due = fn (Builder $q): Builder => $q->where(fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));
        $queued = 0;

        foreach (DataSourceConnection::query()->tap($due)->pluck('id') as $id) {
            // Claimed only if still due, so two schedulers never both queue it.
            if (DataSourceConnection::query()->whereKey($id)->tap($due)->update(['next_check_at' => now()->startOfMinute()->addMinutes(self::EVERY_MINUTES)]) === 1) {
                SyncDataSourceConnection::dispatch($id);
                $queued++;
            }
        }

        return $queued;
    }
}
