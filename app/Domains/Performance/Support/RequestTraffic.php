<?php

declare(strict_types=1);

namespace App\Domains\Performance\Support;

use App\Domains\Performance\Models\RequestCount;
use App\Domains\Shared\Support\Sampling;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every request of a window as rows of (environment, method, route, status_code,
 * occurred_at, requests, duration_us): the exact per-minute counts where the SDK sends
 * them, and the request runs weighed by their sample rate where it does not (an older SDK).
 *
 * Per environment, the runs count up to the first counted minute in the window and the
 * counts from then on, so no request is counted twice. A host still on an older SDK next
 * to one that counts in the same environment goes uncounted from that minute.
 */
final class RequestTraffic
{
    /**
     * @param  int|list<int>  $projects
     */
    public static function query(int|array $projects, CarbonInterface $from, ?CarbonInterface $until = null, ?string $environment = null): Builder
    {
        $projects = (array) $projects;
        $counted = fn () => RequestCount::query()->whereIn('project_id', $projects)->whereMinuteBetween($from, $until)->whereEnvironment($environment)->toBase();

        $firstCounted = $counted()->groupBy('project_id', 'environment')->selectRaw('project_id, environment, MIN(minute) AS first');

        $counts = $counted()->selectRaw('project_id, environment, method, route, status_code, minute AS occurred_at, count AS requests, duration_us');

        $weight = Sampling::weight('r');
        $runs = DB::table('request_runs as r')
            ->leftJoinSub($firstCounted, 'c', fn ($join) => $join->on('c.project_id', '=', 'r.project_id')->whereRaw("c.environment = COALESCE(r.environment, '')"))
            ->whereIn('r.project_id', $projects)
            ->where('r.occurred_at', '>=', $from)
            ->when($until !== null)->where('r.occurred_at', '<', $until)
            ->when($environment !== null)->where('r.environment', $environment)
            ->where(fn (Builder $q) => $q->whereNull('c.first')->orWhereColumn('r.occurred_at', '<', 'c.first'))
            ->selectRaw("r.project_id, COALESCE(r.environment, '') AS environment, r.method, COALESCE(r.route, r.url) AS route, r.status_code, r.occurred_at, {$weight} AS requests, {$weight} * r.duration_us AS duration_us");

        return DB::query()->fromSub($counts->unionAll($runs), 'traffic');
    }
}
