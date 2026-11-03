<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;
use Illuminate\Support\Facades\DB;

/**
 * p75 of each Core Web Vital per browser page, as Google scores them.
 */
class WebVitalsQuery
{
    /**
     * @return list<array{route: string, views: int, lcp_us: int|null, inp_us: int|null, cls: float|null}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, int $limit = 100): array
    {
        return StatisticsCache::remember('vitals', $period, [$projectId, $environment, $limit], fn (): array => $this->compute($projectId, $period, $environment, $limit));
    }

    /**
     * @return list<array{route: string, views: int, lcp_us: int|null, inp_us: int|null, cls: float|null}>
     */
    private function compute(int $projectId, Period $period, ?string $environment, int $limit): array
    {
        // ponytail: percentile_disc instead of the Percentiles helper, since each vital
        // has its own nulls and would need its own ranking window.
        return DB::table('web_vitals')
            ->where('project_id', $projectId)
            ->where('occurred_at', '>=', $period->since())
            ->when($environment !== null)->where('environment', $environment)
            ->selectRaw('route, COUNT(*) AS views')
            ->selectRaw('percentile_disc(0.75) WITHIN GROUP (ORDER BY lcp_us) AS lcp_us')
            ->selectRaw('percentile_disc(0.75) WITHIN GROUP (ORDER BY inp_us) AS inp_us')
            ->selectRaw('percentile_disc(0.75) WITHIN GROUP (ORDER BY cls) AS cls')
            ->groupBy('route')
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'route' => (string) ((array) $row)['route'],
                'views' => (int) ((array) $row)['views'],
                'lcp_us' => ((array) $row)['lcp_us'] !== null ? (int) ((array) $row)['lcp_us'] : null,
                'inp_us' => ((array) $row)['inp_us'] !== null ? (int) ((array) $row)['inp_us'] : null,
                'cls' => ((array) $row)['cls'] !== null ? round((float) ((array) $row)['cls'], 3) : null,
            ])
            ->values()
            ->all();
    }
}
