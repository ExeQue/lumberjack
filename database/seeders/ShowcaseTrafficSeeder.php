<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestCount;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Performance\Models\SpanRun;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Database\Seeders\Support\ShowcaseCatalog;
use Illuminate\Database\Seeder;

/**
 * Web traffic of the showcase projects: exact per-minute request counts for the last
 * week, sampled request runs for the last month (each the SDK's pick of one in 1 /
 * sample_rate), and for some of those runs their queries and spans, plus the web
 * vitals of the browser pages. Traffic follows the day and the week, the bad release
 * shows as a spike of checkout errors. A project that has request runs is skipped.
 */
class ShowcaseTrafficSeeder extends Seeder
{
    /** The share of sampled runs whose queries and spans the SDK kept too. */
    private const float DETAIL_SHARE = 0.2;

    /** Before the counts started, the SDK sampled four times less. */
    private const float OLD_RATE_FACTOR = 0.25;

    /** Staging is a few people clicking through in office hours. */
    private const float STAGING_SCALE = 0.025;

    private Bulk $bulk;

    /** @var array<string, array<string, mixed>> factory-made rows to copy, by series */
    private array $templates = [];

    public function run(): void
    {
        $this->bulk = new Bulk();

        foreach (Showcase::projects() as [$project, $spec]) {
            if ($spec['routes'] === [] || RequestRun::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $this->templates = [];

            foreach (array_keys($spec['hosts']) as $environment) {
                $this->traffic($project, $spec, (string) $environment);
            }
        }

        $this->bulk->flush();
    }

    /** @param  array<string, mixed>  $spec */
    private function traffic(Project $project, array $spec, string $environment): void
    {
        $staging = $environment !== 'production';
        $scale = $staging ? self::STAGING_SCALE : 1.0;
        $shape = $staging || in_array($spec['slug'], ['orders-api', 'customer-portal'], true) ? 'office' : 'web';
        $rate = $staging ? 0.25 : (float) $spec['sample_rate'];
        $routes = $spec['routes'];
        $weights = array_map(fn (array $route): float => (float) $route[3], $routes);
        $share = array_map(fn (float $w): float => $w / array_sum($weights), $weights);
        $bad = Showcase::badRelease($spec);
        [$badFrom, $badTo] = $bad !== null && ! $staging ? [$bad[0]->getTimestamp(), $bad[1]->getTimestamp()] : [0, 0];
        $now = Showcase::now()->getTimestamp();
        $countsFrom = $now - ShowcaseCatalog::COUNT_DAYS * 86400;
        $noise = [];
        $loads = [];

        // Plain timestamps: a Carbon per minute of a month is most of the time otherwise.
        for ($ts = $now - ShowcaseCatalog::RUN_DAYS * 86400; $ts < $now; $ts += 60) {
            $noise[intdiv($ts, 86400)] ??= 0.9 + 0.2 * Showcase::unit();
            $load = $loads[intdiv($ts, 900)] ??= Showcase::load(CarbonImmutable::createFromTimestamp($ts), $shape);
            $mean = $spec['peak'] * $scale * $load * $noise[intdiv($ts, 86400)];
            $broken = $ts >= $badFrom && $ts < $badTo;

            // Before the counts: only the runs, picked at the old, lower rate.
            if ($ts < $countsFrom) {
                for ($n = Showcase::poisson($mean * $rate * self::OLD_RATE_FACTOR); $n > 0; $n--) {
                    $i = (int) Showcase::pick($share);
                    [$status, $us] = $this->request($routes[$i], $broken, $load);
                    $this->sampledRun($project, $spec, $environment, $routes[$i], $ts, $status, $us, $rate * self::OLD_RATE_FACTOR);
                }

                continue;
            }

            $minute = date('Y-m-d H:i:s', $ts);

            foreach ($routes as $i => $route) {
                $sums = [];

                for ($n = Showcase::poisson($mean * $share[$i]); $n > 0; $n--) {
                    [$status, $us] = $this->request($route, $broken, $load);
                    $sums[$status] ??= [0, 0];
                    $sums[$status][0]++;
                    $sums[$status][1] += $us;

                    if (Showcase::chance($rate)) {
                        $this->sampledRun($project, $spec, $environment, $route, $ts, $status, $us, $rate);
                    }
                }

                foreach ($sums as $status => [$count, $us]) {
                    $row = $this->template("count|{$environment}|{$i}|{$status}", fn () => Bulk::row(
                        RequestCount::factory()->for($project)->forRoute($route[0], $route[1], $status)->onDomain($this->domain($spec, $route)),
                        ['environment' => $environment],
                    ));
                    $row['minute'] = $minute;
                    $row['count'] = $count;
                    $row['duration_us'] = $us;
                    $this->bulk->push('request_counts', $row);
                }
            }
        }
    }

    /**
     * One request's status and duration.
     *
     * @param  array<int, mixed>  $route
     * @return array{0: int, 1: int}
     */
    private function request(array $route, bool $broken, float $load): array
    {
        $extra = $route[5] ?? [];
        $status = 200;
        $roll = Showcase::unit();

        foreach ($extra as $key => $p) {
            $code = $key === 'bad500' ? ($broken ? 500 : null) : (is_numeric($key) ? (int) $key : null);

            if ($code !== null && ($roll -= $p) < 0) {
                $status = $code;

                break;
            }
        }

        $median = $route[4] * (1 + 0.25 * $load) * ($broken && isset($extra['bad500']) ? 1.8 : 1.0);
        $us = Showcase::latency($median * ($status === 422 || $status === 404 || $status === 401 ? 0.4 : 1.0));

        if (Showcase::chance($extra['slow'] ?? 0.01)) {
            $us *= mt_rand(5, 12);
        }

        return [$status, $us];
    }

    /**
     * A sampled run of the request, and for some its queries, spans and web vitals.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, mixed>  $route
     */
    private function sampledRun(Project $project, array $spec, string $environment, array $route, int $minute, int $status, int $us, float $rate): void
    {
        $at = CarbonImmutable::createFromTimestampMs($minute * 1000 + mt_rand(0, 59_999));
        $trace = Showcase::traceId();
        $row = $this->template("run|{$environment}|{$route[1]}|{$route[0]}", fn () => Bulk::row(
            RequestRun::factory()->for($project)->onDomain($this->domain($spec, $route))->forRoute($route[0], $route[1], $route[2]),
            ['environment' => $environment],
        ));
        $row['trace_id'] = $trace;
        $row['release'] = Showcase::release($spec, $at);
        $row['host'] = Showcase::any($spec['hosts'][$environment]);
        $row['status_code'] = $status;
        $row['duration_us'] = $us;
        $row['memory_peak'] = mt_rand(18, 64) * 1024 * 1024;
        $row['sample_rate'] = round($rate, 4);
        $row['sampled'] = true;
        $row['occurred_at'] = Showcase::ms($at);
        $this->bulk->push('request_runs', $row);

        if (Showcase::chance(self::DETAIL_SHARE)) {
            $this->details($project, $spec, $environment, $route, $at, $us, $trace, $rate * self::DETAIL_SHARE);
        }

        if ($route[0] === 'GET' && in_array($route[1], $spec['pages'], true) && Showcase::chance(0.15)) {
            $this->vitals($project, $spec, $environment, $route[1], $at);
        }
    }

    /**
     * The queries and spans of a run, laid out one after the other inside it.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, mixed>  $route
     */
    private function details(Project $project, array $spec, string $environment, array $route, CarbonImmutable $at, int $us, string $trace, float $rate): void
    {
        $offset = (int) ($us * 0.08);
        $connection = str_contains(implode(' ', array_column($spec['checks'], 1)), 'database:mysql') ? 'mysql' : 'pgsql';

        foreach ($spec['queries'][$route[0].' '.$route[1]] ?? $spec['queries'][$route[1]] ?? [] as $q => $query) {
            for ($repeat = $query[3] ?? 1; $repeat > 0; $repeat--) {
                $duration = Showcase::latency($query[2], 0.4);
                $row = $this->template("query|{$environment}|{$route[0]}{$route[1]}|{$q}", fn () => Bulk::row(
                    QueryRun::factory()->for($project)->forSql($query[0], $query[1]),
                    ['environment' => $environment, 'connection' => $connection],
                ));
                $row['trace_id'] = $trace;
                $row['duration_us'] = $duration;
                $row['occurred_at'] = Showcase::ms($at->addMicroseconds(min($offset, $us)));
                $row['sample_rate'] = round($rate, 4);
                $row['sampled'] = true;
                $this->bulk->push('query_runs', $row);
                $offset += $duration + mt_rand(100, 900);
            }
        }

        foreach ($spec['spans'][$route[0].' '.$route[1]] ?? $spec['spans'][$route[1]] ?? [] as $s => $span) {
            $factory = match ($span[0]) {
                'http' => SpanRun::factory()->http($span[1], $span[2]),
                'cache' => SpanRun::factory()->cache(str_replace('{slug}', 'oak-desk-lamp', $span[1]), $span[2]),
                'mail' => SpanRun::factory()->mail($span[1]),
                'notification' => SpanRun::factory()->notification($span[1], $span[2]),
                default => SpanRun::factory()->state(['kind' => $span[0], 'name' => $span[1], 'target' => $span[2], 'duration_us' => 600, 'attributes' => null]),
            };
            $row = $this->template("span|{$environment}|{$route[0]}{$route[1]}|{$s}", fn () => Bulk::row($factory->for($project), ['environment' => $environment]));
            $row['trace_id'] = $trace;
            $row['occurred_at'] = Showcase::ms($at->addMicroseconds(min($offset, $us)));

            if ($span[0] === 'http') {
                $row['duration_us'] = Showcase::latency($span[3], 0.35);
                $offset += $row['duration_us'];
            }

            $this->bulk->push('span_runs', $row);
        }
    }

    /**
     * A page view's web vitals; no model holds them, so the row is written as ingest writes it.
     *
     * @param  array<string, mixed>  $spec
     */
    private function vitals(Project $project, array $spec, string $environment, string $route, CarbonImmutable $at): void
    {
        // The search page renders its results late, the product page shifts as images load.
        $lcp = match ($route) {
            '/search' => 2600,
            '/products/{slug}' => 1900,
            '/invoices', '/checkout' => 1600,
            default => 1150,
        };

        $this->bulk->push('web_vitals', [
            'project_id' => $project->id,
            'environment' => $environment,
            'release' => Showcase::release($spec, $at),
            'route' => $route,
            'lcp_us' => Showcase::latency($lcp, 0.4),
            'inp_us' => Showcase::chance(0.7) ? Showcase::latency($route === '/checkout' ? 240 : 90, 0.5) : null,
            'ttfb_us' => Showcase::latency(180, 0.4),
            'fcp_us' => Showcase::latency($lcp * 0.6, 0.4),
            'cls' => round(($route === '/products/{slug}' ? 0.11 : 0.02) * exp(0.6 * Showcase::gauss()), 3),
            'occurred_at' => Showcase::ms($at->addSeconds(2)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, mixed>  $route
     */
    private function domain(array $spec, array $route): string
    {
        return $route[5]['domain'] ?? $spec['domain'] ?? '';
    }

    /**
     * @param  callable(): array<string, mixed>  $make
     * @return array<string, mixed>
     */
    private function template(string $key, callable $make): array
    {
        return $this->templates[$key] ??= $make();
    }
}
