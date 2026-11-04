<?php

declare(strict_types=1);

namespace App\Domains\Performance\Queries;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Performance\Eloquent\Builders\WebVitalBuilder;
use App\Domains\Performance\Enums\DeviceClass;
use App\Domains\Performance\Models\WebVital;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\Percentiles;
use App\Domains\Shared\Support\StatisticsCache;
use Illuminate\Support\Facades\DB;

/**
 * The Browser page's figures: the p75 of each Core Web Vital as Google scores them, over
 * the period, per device class, per bucket and per page, and the browser errors.
 */
class BrowserQuery
{
    /** Google's good thresholds: LCP and INP in µs, CLS unitless. */
    public const int GOOD_LCP_US = 2_500_000;

    public const int GOOD_INP_US = 200_000;

    public const float GOOD_CLS = 0.1;

    public function hasVitals(int $projectId): bool
    {
        return WebVital::query()->where('project_id', $projectId)->exists();
    }

    /**
     * The period's page views and p75 of each vital, the share of views each vital rated
     * good, and the same figures for the period just before; in one pass over both periods.
     *
     * @return array{views: int, lcp_us: int|null, inp_us: int|null, cls: float|null, good: array{lcp: float|null, inp: float|null, cls: float|null}, previous: array{views: int, lcp_us: int|null, inp_us: int|null, cls: float|null}}
     */
    public function summary(int $projectId, Period $period, ?string $environment = null, ?DeviceClass $device = null): array
    {
        return StatisticsCache::remember('browser-summary', $period, [$projectId, $environment, $device?->value], function () use ($projectId, $period, $environment, $device): array {
            $since = $period->since();
            $from = $since->subSeconds((int) now()->diffInSeconds($since, true));
            $at = $since->format('Y-m-d H:i:s.v');

            $row = (array) $this->scope($projectId, $environment, $device)
                ->whereOccurredBetween($from)
                ->selectRaw('COUNT(*) FILTER (WHERE occurred_at >= ?) AS views, COUNT(*) FILTER (WHERE occurred_at < ?) AS previous_views', [$at, $at])
                ->selectRaw(Percentiles::select('lcp_us', '0.75', 'lcp', 'occurred_at >= ?'), [$at])
                ->selectRaw(Percentiles::select('inp_us', '0.75', 'inp', 'occurred_at >= ?'), [$at])
                ->selectRaw(Percentiles::select('cls', '0.75', 'cls_p75', 'occurred_at >= ?'), [$at])
                ->selectRaw(Percentiles::select('lcp_us', '0.75', 'previous_lcp', 'occurred_at < ?'), [$at])
                ->selectRaw(Percentiles::select('inp_us', '0.75', 'previous_inp', 'occurred_at < ?'), [$at])
                ->selectRaw(Percentiles::select('cls', '0.75', 'previous_cls', 'occurred_at < ?'), [$at])
                ->selectRaw('COUNT(lcp_us) FILTER (WHERE occurred_at >= ?) AS lcp_views, COUNT(*) FILTER (WHERE occurred_at >= ? AND lcp_us <= ?) AS lcp_good', [$at, $at, self::GOOD_LCP_US])
                ->selectRaw('COUNT(inp_us) FILTER (WHERE occurred_at >= ?) AS inp_views, COUNT(*) FILTER (WHERE occurred_at >= ? AND inp_us <= ?) AS inp_good', [$at, $at, self::GOOD_INP_US])
                ->selectRaw('COUNT(cls) FILTER (WHERE occurred_at >= ?) AS cls_views, COUNT(*) FILTER (WHERE occurred_at >= ? AND cls <= ?) AS cls_good', [$at, $at, self::GOOD_CLS])
                ->toBase()
                ->first();

            $share = fn (string $vital): ?float => (int) $row[$vital.'_views'] > 0 ? round((int) $row[$vital.'_good'] / (int) $row[$vital.'_views'], 4) : null;

            return [
                'views' => (int) $row['views'],
                'lcp_us' => self::int($row['lcp']),
                'inp_us' => self::int($row['inp']),
                'cls' => self::cls($row['cls_p75']),
                'good' => ['lcp' => $share('lcp'), 'inp' => $share('inp'), 'cls' => $share('cls')],
                'previous' => [
                    'views' => (int) $row['previous_views'],
                    'lcp_us' => self::int($row['previous_lcp']),
                    'inp_us' => self::int($row['previous_inp']),
                    'cls' => self::cls($row['previous_cls']),
                ],
            ];
        });
    }

    /**
     * Page views and p75 LCP per device class, every class listed. Views without a class
     * (an older browser script) are left out.
     *
     * @return list<array{device: string, views: int, lcp_us: int|null}>
     */
    public function devices(int $projectId, Period $period, ?string $environment = null): array
    {
        $rows = StatisticsCache::remember('browser-devices', $period, [$projectId, $environment], fn (): array => $this->scope($projectId, $environment)
            ->whereOccurredBetween($period->since())
            ->whereNotNull('device')
            ->selectRaw('device, COUNT(*) AS views, '.Percentiles::select('lcp_us', '0.75', 'lcp'))
            ->groupBy('device')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->device => ['views' => (int) $row->views, 'lcp_us' => self::int($row->lcp)]])
            ->all());

        return array_map(fn (DeviceClass $device): array => [
            'device' => $device->value,
            'views' => $rows[$device->value]['views'] ?? 0,
            'lcp_us' => $rows[$device->value]['lcp_us'] ?? null,
        ], DeviceClass::cases());
    }

    /**
     * The p75 of each vital per bucket of the period; null where no page was viewed.
     *
     * @return list<array{t: string, lcp_us: int|null, inp_us: int|null, cls: float|null}>
     */
    public function series(int $projectId, Period $period, ?string $environment = null, ?DeviceClass $device = null): array
    {
        $size = $period->bucketSeconds();
        $since = $period->since();

        $byBucket = StatisticsCache::remember('browser-series', $period, [$projectId, $environment, $device?->value], fn (): array => $this->scope($projectId, $environment, $device)
            ->whereOccurredBetween($since)
            ->selectRaw('FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?) * ? AS bucket', [$size, $size])
            ->selectRaw(Percentiles::select('lcp_us', '0.75', 'lcp').', '.Percentiles::select('inp_us', '0.75', 'inp').', '.Percentiles::select('cls', '0.75', 'cls_p75'))
            ->groupByRaw('1')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->bucket => ['lcp_us' => self::int($row->lcp), 'inp_us' => self::int($row->inp), 'cls' => self::cls($row->cls_p75)]])
            ->all());

        $series = [];

        for ($at = intdiv($since->getTimestamp(), $size) * $size; $at <= now()->getTimestamp(); $at += $size) {
            $series[] = ['t' => date(DATE_ATOM, $at), ...($byBucket[$at] ?? ['lcp_us' => null, 'inp_us' => null, 'cls' => null])];
        }

        return $series;
    }

    /**
     * The p75 of each vital per browser page, the most viewed first or the slowest LCP first.
     *
     * @param  'views'|'lcp'  $sort
     * @return list<array{route: string, views: int, lcp_us: int|null, inp_us: int|null, cls: float|null, ttfb_us: int|null, fcp_us: int|null}>
     */
    public function pages(int $projectId, Period $period, ?string $environment = null, ?DeviceClass $device = null, string $sort = 'views', int $limit = 100): array
    {
        return StatisticsCache::remember('browser-pages', $period, [$projectId, $environment, $device?->value, $sort, $limit], fn (): array => $this->computePages($projectId, $period, $environment, $device, $sort, $limit));
    }

    /**
     * @param  'views'|'lcp'  $sort
     * @return list<array{route: string, views: int, lcp_us: int|null, inp_us: int|null, cls: float|null, ttfb_us: int|null, fcp_us: int|null}>
     */
    private function computePages(int $projectId, Period $period, ?string $environment, ?DeviceClass $device, string $sort, int $limit): array
    {
        return array_values($this->scope($projectId, $environment, $device)
            ->whereOccurredBetween($period->since())
            ->selectRaw('route, COUNT(*) AS views')
            ->selectRaw(Percentiles::select('lcp_us', '0.75', 'lcp').', '.Percentiles::select('inp_us', '0.75', 'inp').', '.Percentiles::select('cls', '0.75', 'cls_p75'))
            ->selectRaw(Percentiles::select('ttfb_us', '0.75', 'ttfb').', '.Percentiles::select('fcp_us', '0.75', 'fcp'))
            ->groupBy('route')
            ->orderByRaw($sort === 'lcp' ? 'lcp DESC NULLS LAST, views DESC' : 'views DESC')
            ->orderBy('route')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(function (object $object): array {
                $row = (array) $object;

                return [
                    'route' => (string) $row['route'],
                    'views' => (int) $row['views'],
                    'lcp_us' => self::int($row['lcp']),
                    'inp_us' => self::int($row['inp']),
                    'cls' => self::cls($row['cls_p75']),
                    'ttfb_us' => self::int($row['ttfb']),
                    'fcp_us' => self::int($row['fcp']),
                ];
            })
            ->all());
    }

    /**
     * Occurrences of browser issues in the period, and the issues with the most of them.
     *
     * @return array{occurrences: int, issues: list<array{id: string, title: string, message: string|null, occurrences: int}>}
     */
    public function errors(int $projectId, Period $period, ?string $environment = null, int $limit = 5): array
    {
        return StatisticsCache::remember('browser-errors', $period, [$projectId, $environment, $limit], function () use ($projectId, $period, $environment, $limit): array {
            // The total over every issue rides along on each row, so the top list and the
            // count are one query.
            $rows = DB::table('occurrences')
                ->join('issues', 'issues.id', '=', 'occurrences.issue_id')
                ->where('occurrences.project_id', $projectId)
                ->where('occurrences.occurred_at', '>=', $period->since())
                ->where('issues.source', IssueSource::Browser->value)
                ->when($environment !== null)->where('occurrences.environment', $environment)
                ->groupBy('issues.id')
                ->selectRaw('issues.uuid, issues.title, issues.message, COUNT(*) AS occurrences, SUM(COUNT(*)) OVER () AS total')
                ->orderByDesc('occurrences')
                ->orderByDesc('issues.id')
                ->limit($limit)
                ->get()
                ->map(fn (object $object): array => (array) $object);

            return [
                'occurrences' => (int) ($rows->first()['total'] ?? 0),
                'issues' => $rows->map(fn (array $row): array => [
                    'id' => (string) $row['uuid'],
                    'title' => (string) $row['title'],
                    'message' => $row['message'] !== null ? (string) $row['message'] : null,
                    'occurrences' => (int) $row['occurrences'],
                ])->values()->all(),
            ];
        });
    }

    private function scope(int $projectId, ?string $environment, ?DeviceClass $device = null): WebVitalBuilder
    {
        return WebVital::query()->where('project_id', $projectId)->whereEnvironment($environment)->whereDevice($device);
    }

    private static function int(mixed $value): ?int
    {
        return $value !== null ? (int) $value : null;
    }

    private static function cls(mixed $value): ?float
    {
        return $value !== null ? round((float) $value, 3) : null;
    }
}
