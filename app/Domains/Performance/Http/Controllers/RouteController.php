<?php

declare(strict_types=1);

namespace App\Domains\Performance\Http\Controllers;

use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Queries\WebVitalsQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Presenters\ProjectPresenter;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Http\Concerns\ReadsDashboardFilters;
use App\Domains\Shared\Support\Percentiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RouteController
{
    use ReadsDashboardFilters;

    public function __invoke(Request $request, Project $project, RouteStatisticsQuery $query, WebVitalsQuery $vitals, ProjectPresenter $presenter): Response
    {
        Gate::authorize('view', $project);

        $period = $this->period($request);
        $environment = $this->environment($request);
        $sort = (string) $request->query('sort', 'p95');
        $search = trim((string) $request->query('search', ''));
        $selected = (string) $request->query('route', '');
        $method = (string) $request->query('method', '');

        return Inertia::render('routes/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'sort' => $sort, 'search' => $search, 'route' => $selected, 'method' => $method],
            'routes' => fn (): array => $this->withSeries($project, $period, $environment, $query->get($project->id, $period, $environment, $sort, $search)),
            'vitals' => fn (): array => $vitals->get($project->id, $period, $environment),
            'detail' => fn (): ?array => $selected === '' ? null : $this->detail($project, $period, $environment, $method, $selected),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Project $project, Period $period, ?string $environment, string $method, string $route): array
    {
        $since = $period->since();
        $size = $period->bucketSeconds();
        $scope = fn ($q) => $q->where('project_id', $project->id)->where('occurred_at', '>=', $since)
            ->where('method', $method)->where(fn ($r) => $r->where('route', $route)->orWhere(fn ($u) => $u->whereNull('route')->where('url', $route)))
            ->when($environment !== null)->where('environment', $environment);

        $p50 = Percentiles::select('duration_us', 0.50, 'p50');
        $p95 = Percentiles::select('duration_us', 0.95, 'p95');

        $byBucket = DB::table('request_runs')->tap($scope)
            ->selectRaw('FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?) * ? AS bucket, duration_us', [$size, $size])
            ->get()
            ->groupBy(fn ($row): int => (int) $row->bucket)
            ->map(function ($rows): array {
                $values = $rows->pluck('duration_us')->map(fn ($v): int => (int) $v)->sort()->values();
                $at = fn (float $p): int => (int) $values->get(max(0, (int) ceil($values->count() * $p) - 1), 0);

                return ['p50' => $at(0.5), 'p95' => $at(0.95)];
            });

        // Every bucket of the period, empty where no request came in, so one busy hour is
        // one bar among the rest and not the whole chart.
        $hourly = [];
        $start = intdiv($since->getTimestamp(), $size) * $size;

        for ($at = $start; $at <= now()->getTimestamp(); $at += $size) {
            $hourly[] = ['t' => date(DATE_ATOM, $at), 'p50' => $byBucket[$at]['p50'] ?? null, 'p95' => $byBucket[$at]['p95'] ?? null];
        }

        unset($p50, $p95);

        return [
            'method' => $method,
            'route' => $route,
            'hourly' => $hourly,
            'slowest' => RequestRun::query()->tap($scope)->orderByDesc('duration_us')->limit(10)->get()
                ->map(fn (RequestRun $r): array => [
                    'at' => $r->occurred_at->toIso8601String(),
                    'host' => $r->host,
                    'status' => $r->status_code,
                    'duration_us' => $r->duration_us,
                    'trace_id' => $r->trace_id,
                    'queries' => $r->trace_id !== null ? DB::table('query_runs')->where('project_id', $project->id)->where('trace_id', $r->trace_id)->count() : null,
                ])->all(),
        ];
    }

    /**
     * Adds each route's requests per bucket for the sparkline column.
     *
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    private function withSeries(Project $project, Period $period, ?string $environment, array $routes): array
    {
        $size = $period->bucketSeconds();
        $start = intdiv($period->since()->getTimestamp(), $size);

        $counts = DB::table('request_runs')
            ->where('project_id', $project->id)
            ->where('occurred_at', '>=', $period->since())
            ->when($environment !== null)->where('environment', $environment)
            ->selectRaw('method, COALESCE(route, url) AS route, FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?) AS b, COUNT(*) AS c', [$size])
            ->groupByRaw('method, COALESCE(route, url), b')
            ->get()
            ->groupBy(fn (object $row): string => ((array) $row)['method'].' '.((array) $row)['route']);

        return array_map(function (array $route) use ($counts, $start, $period): array {
            $rows = $counts->get($route['method'].' '.$route['route']);
            $series = array_fill(0, $period->buckets() + 1, 0);

            foreach ($rows ?? [] as $row) {
                $values = (array) $row;
                $index = (int) $values['b'] - $start;

                if ($index >= 0 && $index < count($series)) {
                    $series[$index] = (int) $values['c'];
                }
            }

            return [...$route, 'series' => $series];
        }, $routes);
    }
}
