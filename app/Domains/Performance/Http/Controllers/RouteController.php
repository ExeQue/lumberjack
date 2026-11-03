<?php

declare(strict_types=1);

namespace App\Domains\Performance\Http\Controllers;

use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Queries\RouteStatisticsQuery;
use App\Domains\Performance\Queries\WebVitalsQuery;
use App\Domains\Performance\Support\RequestTraffic;
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
        // A domain to narrow every figure to; the open route's own when the table is split.
        $domain = $request->query('domain');
        $domain = is_string($domain) && $domain !== '' ? mb_strtolower(mb_substr($domain, 0, 255)) : null;
        $routeDomain = $request->query('route_domain');
        $routeDomain = is_string($routeDomain) && $routeDomain !== '' ? mb_strtolower(mb_substr($routeDomain, 0, 255)) : null;

        return Inertia::render('routes/index', [
            'project' => $presenter->header($project, $request->user()),
            'filters' => [...$this->filters($request), 'sort' => $sort, 'search' => $search, 'route' => $selected, 'method' => $method, 'domain' => $domain, 'route_domain' => $routeDomain],
            'domains' => fn (): array => $query->domains($project->id, $period, $environment),
            'routes' => fn (): array => $this->withSeries($project, $period, $environment, $domain, $query->get($project->id, $period, $environment, $sort, $search, domain: $domain)),
            'vitals' => fn (): array => $vitals->get($project->id, $period, $environment),
            'sample_rate' => fn (): ?float => $query->sampleRate($project->id, $period, $environment, $domain),
            'detail' => fn (): ?array => $selected === '' ? null : $this->detail($project, $period, $environment, $domain ?? $routeDomain, $method, $selected),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Project $project, Period $period, ?string $environment, ?string $domain, string $method, string $route): array
    {
        $since = $period->since();
        $size = $period->bucketSeconds();
        // A named route by its index; only a request no route matched is found by its url.
        // Split rather than OR-ed, so the (project_id, route, occurred_at) index is used.
        $named = DB::table('request_runs')->where('project_id', $project->id)->where('route', $route)->where('occurred_at', '>=', $since)->exists();
        $scope = fn ($q) => $q->where('project_id', $project->id)->where('occurred_at', '>=', $since)->where('method', $method)
            ->when($named)->where('route', $route)
            ->when(! $named)->whereNull('route')
            ->when(! $named)->where('url', $route)
            ->when($environment !== null)->where('environment', $environment)
            ->when($domain !== null)->where('domain', $domain);

        // Percentiles from the runs the SDK picked; a run kept only for being slow would skew them.
        $byBucket = DB::table('request_runs')->tap($scope)->where('sampled', true)
            ->selectRaw("FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?) * ? AS bucket, ".Percentiles::select('duration_us', '0.50', 'p50').', '.Percentiles::select('duration_us', '0.95', 'p95'), [$size, $size])
            ->groupByRaw('1')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->bucket => ['p50' => (int) $row->p50, 'p95' => (int) $row->p95]]);

        // Every bucket of the period, empty where no request came in, so one busy hour is
        // one bar among the rest and not the whole chart.
        $hourly = [];
        $start = intdiv($since->getTimestamp(), $size) * $size;

        for ($at = $start; $at <= now()->getTimestamp(); $at += $size) {
            $hourly[] = ['t' => date(DATE_ATOM, $at), 'p50' => $byBucket[$at]['p50'] ?? null, 'p95' => $byBucket[$at]['p95'] ?? null];
        }

        return [
            'method' => $method,
            'route' => $route,
            'domain' => $domain,
            'route_name' => RequestRun::query()->tap($scope)->whereNotNull('route_name')->latest('occurred_at')->value('route_name'),
            'hourly' => $hourly,
            'slowest' => RequestRun::query()->tap($scope)->orderByDesc('duration_us')->limit(10)->get()
                ->map(fn (RequestRun $r): array => [
                    'at' => $r->occurred_at->toIso8601String(),
                    'host' => $r->host,
                    'domain' => $r->domain,
                    'status' => $r->status_code,
                    'duration_us' => $r->duration_us,
                    'trace_id' => $r->trace_id,
                    'queries' => $r->trace_id !== null ? DB::table('query_runs')->where('project_id', $project->id)->where('trace_id', $r->trace_id)->count() : null,
                ])->all(),
        ];
    }

    /**
     * Adds each route's requests per bucket for the sparkline column: of its own domain
     * when the table is split by domain, else of every domain the filter lets in.
     *
     * @param  list<array<string, mixed>>  $routes
     * @return list<array<string, mixed>>
     */
    private function withSeries(Project $project, Period $period, ?string $environment, ?string $domain, array $routes): array
    {
        $size = $period->bucketSeconds();
        $start = intdiv($period->since()->getTimestamp(), $size);

        $counts = RequestTraffic::query($project->id, $period->since(), null, $environment, $domain)
            ->selectRaw('method, route, domain, FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?) AS b, SUM(requests) AS c', [$size])
            ->groupByRaw('method, route, domain, b')
            ->get()
            ->groupBy(fn (object $row): string => ((array) $row)['method'].' '.((array) $row)['route']);

        return array_map(function (array $route) use ($counts, $start, $period): array {
            $rows = $counts->get($route['method'].' '.$route['route']);
            $series = array_fill(0, $period->buckets() + 1, 0);

            foreach ($rows ?? [] as $row) {
                $values = (array) $row;
                $index = (int) $values['b'] - $start;

                if ($index >= 0 && $index < count($series) && ($route['domain'] === null || $route['domain'] === $values['domain'])) {
                    $series[$index] += (int) round((float) $values['c']);
                }
            }

            return [...$route, 'series' => $series];
        }, $routes);
    }
}
