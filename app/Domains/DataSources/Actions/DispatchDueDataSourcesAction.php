<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Jobs\FetchDataSource;
use App\Domains\DataSources\Models\DataSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Runs every minute: queues each data source whose time has come, and moves its next fetch
 * on first, so a slow queue never fetches one twice. Like DispatchDueHealthChecksAction.
 */
class DispatchDueDataSourcesAction
{
    /**
     * @return int sources queued
     */
    public function execute(): int
    {
        $due = fn (Builder $q): Builder => $q->where(fn (Builder $q) => $q->whereNull('next_fetch_at')->orWhere('next_fetch_at', '<=', now()));
        $queued = 0;

        // Only what the claim needs: the job loads the rest, headers too, when it runs.
        DataSource::query()->select(['id', 'interval_minutes'])->whereNotConnected()->tap($due)->chunkById(500, function (Collection $sources) use ($due, &$queued): void {
            foreach ($sources as $source) {
                // Claimed only if still due, so two schedulers never both queue it.
                $claimed = DataSource::query()->whereKey($source->id)->tap($due)
                    ->update(['next_fetch_at' => now()->startOfMinute()->addMinutes(max(1, $source->interval_minutes))]);

                if ($claimed === 1) {
                    FetchDataSource::dispatch($source);
                    $queued++;
                }
            }
        });

        return $queued;
    }
}
