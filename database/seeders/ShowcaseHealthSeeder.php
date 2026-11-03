<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Database\Seeders\Support\ShowcaseCatalog;
use Illuminate\Database\Seeder;

/**
 * Health of the showcase projects: the checks each app reports (database, cache, disk,
 * queues, its own closures) and the ones Lumberjack runs (http, ssl, dns, tcp and a
 * heartbeat), two weeks of results with the outages they had, and each host's agent
 * with a day of CPU, memory and disk samples. Right now one check is recovering from
 * an incident, one is down, two are degraded and one has gone stale. A project with
 * health checks is skipped.
 */
class ShowcaseHealthSeeder extends Seeder
{
    /** Older results every half hour; the last two hours each minute. */
    private const int SPARSE_MINUTES = 30;

    private const int DENSE_MINUTES = 120;

    private Bulk $bulk;

    public function run(): void
    {
        $this->bulk = new Bulk();

        foreach (Showcase::projects() as [$project, $spec]) {
            if (HealthCheck::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            foreach ($spec['checks'] as $check) {
                $this->check($project, $spec, $check);
            }

            $this->agents($project, $spec);
            $this->bulk->flush();
        }

        $this->bulk->flush();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, mixed>  $entry  source, slug or type, kind or name, options
     */
    private function check(Project $project, array $spec, array $entry): void
    {
        [$source, $slugOrType, $kindOrName, $options] = $entry + [3 => []];
        $now = Showcase::now();
        $monitored = $source === 'monitored';
        $type = $monitored ? HealthCheckType::from($slugOrType) : null;
        $kind = $monitored ? $slugOrType : $kindOrName;
        $interval = match ($type) {
            HealthCheckType::Ssl => 60,
            HealthCheckType::Dns => 5,
            HealthCheckType::Heartbeat => 60,
            default => 1,
        };
        $config = array_diff_key($options, array_flip(['incidents', 'stale', 'used', 'days_left']));

        $check = (
            $monitored
            ? HealthCheck::factory()->for($project)->monitored($type, $config)
            : HealthCheck::factory()->for($project)->reported($slugOrType)
        )->create([
            'name' => $monitored ? $kindOrName : null,
            'interval_minutes' => $monitored ? $interval : null,
            'description' => $slugOrType === 'payments-api' ? 'Stripe answers a balance request within 2 seconds.' : null,
            'ping_token' => $type === HealthCheckType::Heartbeat ? substr(hash('sha256', $project->slug.$kindOrName), 0, 40) : null,
            'created_at' => $now->subDays(40),
        ]);

        $hosts = $monitored ? [''] : (in_array($kind, ['queue', 'closure'], true) && $slugOrType !== 'payments-api' ? [$spec['workers']['production'][0]] : $spec['hosts']['production']);
        $incidents = array_values(array_map(fn (array $i): array => [
            Showcase::daysAgo($i[0]),
            $i[1] !== null ? Showcase::daysAgo($i[0])->addMinutes($i[1]) : null,
            HealthStatus::from($i[2]),
            (string) $i[3],
        ], $options['incidents'] ?? []));
        $staleSince = isset($options['stale']) ? Showcase::daysAgo($options['stale']) : null;
        $worst = HealthStatus::Ok;
        $lastAt = null;

        foreach ($hosts as $host) {
            [$state, $lastAt] = $this->results($check, $host, $kind, $options, $incidents, $staleSince, $interval);
            $state->save();
            $worst = $state->status->worseThan($worst) ? $state->status : $worst;
        }

        foreach ($incidents as [$from, $to, $status, $message]) {
            HealthOutage::factory()->of($check)->when($to === null || $to->addMinutes(5 * $interval) > $now)->ongoing()->create([
                'status' => $status,
                'first_message' => $message,
                'started_at' => $from->addMinutes(2 * $interval),
                'ended_at' => $to?->addMinutes(5 * $interval),
            ]);
        }

        $check->forceFill([
            'status' => $worst,
            'status_changed_at' => $incidents !== [] ? end($incidents)[0] : $check->created_at,
            'last_result_at' => $lastAt,
            // ponytail: a while off, so the seeded state shows before Lumberjack runs the checks
            // against the placeholder hosts, which do not resolve; a real target is the upgrade.
            'next_run_at' => $monitored ? $now->addHours(2) : null,
            'last_ping_at' => $type === HealthCheckType::Heartbeat ? ($incidents !== [] ? $now->subDays(2)->setTime(1, 15) : $now->subHours(4)) : null,
            'alerted_status' => $worst,
            'alerted_at' => $incidents !== [] ? end($incidents)[0]->addMinutes(3) : null,
        ])->save();
    }

    /**
     * Writes a host's results and returns its state now, with when it last reported.
     *
     * @param  array<string, mixed>  $options
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null, 2: HealthStatus, 3: string}>  $incidents
     * @return array{0: HealthCheckHost, 1: CarbonImmutable}
     */
    private function results(HealthCheck $check, string $host, string $kind, array $options, array $incidents, ?CarbonImmutable $staleSince, int $interval): array
    {
        $now = Showcase::now();
        $until = $staleSince ?? $now;
        $templates = [];
        $okStreak = 0;
        $failStreak = 0;
        $last = null;

        for ($at = $now->subDays(ShowcaseCatalog::HEALTH_DAYS); $at <= $until; $at = $this->next($at, $incidents, $interval)) {
            $incident = $this->incidentAt($incidents, $at);
            $status = $incident === null ? 'ok' : ($incident[2] === HealthStatus::Down ? 'failed' : 'warning');
            [$message, $metrics, $us] = $this->measure($kind, $options, $at, $incident);
            $row = $templates[$status] ??= Bulk::row(match ($status) {
                'failed' => HealthCheckResult::factory()->of($check, $host)->failed(''),
                'warning' => HealthCheckResult::factory()->of($check, $host)->warning(''),
                default => HealthCheckResult::factory()->of($check, $host),
            });
            $this->bulk->push('health_check_results', [...$row, 'message' => $message, 'metrics' => json_encode($metrics), 'duration_us' => $us, 'checked_at' => $at->format('Y-m-d H:i:s')]);

            [$okStreak, $failStreak] = $status === 'ok' ? [$okStreak + 1, 0] : [0, $failStreak + 1];
            $last = [$at, $status, $message, $metrics, $us, $incident];
        }

        [$at, $status, $message, $metrics, $us, $incident] = $last;
        $previous = collect($incidents)->filter(fn (array $i): bool => $i[1] !== null && $i[1] <= $now)->last();
        $factory = HealthCheckHost::factory();

        $factory = match (true) {
            $status === 'failed' => $factory->down($failStreak, $message),
            $status === 'warning' => $factory->degraded($failStreak, $message),
            $previous !== null && $okStreak < $check->recover_after => $factory->recovering($previous[2], $okStreak),
            default => $factory,
        };

        return [$factory->make([
            'health_check_id' => $check->id,
            'host' => $host,
            'ok_streak' => $status === 'ok' ? $okStreak : 0,
            'last_duration_us' => $us,
            'last_metrics' => $metrics,
            'last_result_at' => $at,
            'interval_s' => $interval * 60,
            'created_at' => $check->created_at,
        ]), $at];
    }

    /**
     * The next result's time: each interval inside incidents and the last two hours,
     * every half hour before.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null, 2: HealthStatus, 3: string}>  $incidents
     */
    private function next(CarbonImmutable $at, array $incidents, int $interval): CarbonImmutable
    {
        $dense = $at >= Showcase::now()->subMinutes(self::DENSE_MINUTES)
            || $this->incidentAt($incidents, $at->addMinutes(self::SPARSE_MINUTES)) !== null
            || $this->incidentAt($incidents, $at) !== null
            || collect($incidents)->contains(fn (array $i): bool => $i[1] !== null && $at < $i[1]->addMinutes(10) && $at >= $i[1]);

        return $at->addMinutes($dense ? $interval : max($interval, self::SPARSE_MINUTES));
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null, 2: HealthStatus, 3: string}>  $incidents
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null, 2: HealthStatus, 3: string}|null
     */
    private function incidentAt(array $incidents, CarbonImmutable $at): ?array
    {
        foreach ($incidents as $incident) {
            if ($at >= $incident[0] && ($incident[1] === null || $at < $incident[1])) {
                return $incident;
            }
        }

        return null;
    }

    /**
     * A result's message, metrics and duration, as each kind of check reports them.
     *
     * @param  array<string, mixed>  $options
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable|null, 2: HealthStatus, 3: string}|null  $incident
     * @return array{0: string|null, 1: array<string, mixed>, 2: int}
     */
    private function measure(string $kind, array $options, CarbonImmutable $at, ?array $incident): array
    {
        $load = Showcase::load($at);
        $us = Showcase::latency(match ($kind) {
            'db' => 2.5, 'cache' => 0.7, 'disk' => 0.5, 'queue' => 1.2, 'closure' => 180,
            'http' => 140, 'ssl' => 60, 'dns' => 12, 'tcp' => 3, default => 5,
        } * (1 + $load) * ($incident !== null && $incident[2] === HealthStatus::Degraded ? 12 : 1), 0.3);
        $latency = ['latency_ms' => round($us / 1000, 3)];
        $age = Showcase::now()->diffInDays($at, true);
        $used = round(100 * (($options['used'] ?? 0.62) - 0.002 * $age), 1);
        $waiting = $incident !== null ? 3_800 + mt_rand(0, 900) : Showcase::poisson(6 * $load);
        $daysLeft = ($options['days_left'] ?? 74) + (int) $age;

        $metrics = match ($kind) {
            'db' => [...$latency, 'connections' => 8 + (int) (34 * $load) + mt_rand(0, 4), 'max_connections' => 100, 'size_bytes' => (int) ((2.31 - 0.006 * $age) * 1024 ** 3)],
            'disk' => [...$latency, 'used_percent' => $used],
            'queue' => ['size' => $waiting],
            'http' => [...$latency, 'status_code' => $incident !== null && $incident[2] === HealthStatus::Down ? 500 : 200],
            'ssl' => ['days_left' => $daysLeft],
            'dns' => [...$latency, 'count' => 2],
            'heartbeat' => [],
            default => $latency,
        };

        $message = $incident[3] ?? match ($kind) {
            'disk' => "{$used}% used",
            'queue' => "{$waiting} waiting",
            'ssl' => "Valid for {$daysLeft} days",
            default => null,
        };

        return [$message, $metrics, $incident !== null && $incident[2] === HealthStatus::Down && $kind === 'http' ? 10_000_000 : $us];
    }

    /**
     * Each host's agent with a day of samples every two minutes; the staging agent went quiet.
     *
     * @param  array<string, mixed>  $spec
     */
    private function agents(Project $project, array $spec): void
    {
        $now = Showcase::now();
        [$cores, $memory, $disk] = $spec['machine'];

        foreach ($spec['hosts'] + ['workers' => $spec['workers']['production']] as $environment => $hosts) {
            foreach ($hosts as $host) {
                if (ProjectAgent::query()->where('project_id', $project->id)->where('host', $host)->exists()) {
                    continue;
                }

                $quiet = $environment === 'staging';
                $seen = $quiet ? $now->subMinutes(47) : $now->subSeconds(mt_rand(5, 50));
                $agent = ProjectAgent::factory()->for($project)->onHost($host)->create(['last_seen_at' => $seen, 'created_at' => $now->subDays(30)]);
                $row = Bulk::row(HostSample::factory()->for($agent, 'agent')->machine($cores, $memory, $disk));
                $worker = $environment === 'workers';
                $baseDisk = 0.35 + 0.3 * Showcase::unit();

                for ($at = $now->subDay(); $at <= $seen; $at = $at->addMinutes(2)) {
                    $load = Showcase::load($at, $worker ? 'worker' : 'web');
                    $cpu = max(0.02, min(1.4, 0.08 + 0.55 * $load + 0.06 * Showcase::gauss()));
                    $mem = max(0.2, min(0.97, 0.42 + 0.25 * $load + 0.02 * Showcase::gauss()));

                    $this->bulk->push('host_samples', [...$row,
                        'cpu_load_1m' => round($cpu * $cores, 2),
                        'memory_used_bytes' => (int) ($mem * $row['memory_total_bytes']),
                        'disk_used_bytes' => (int) (($baseDisk - 0.00002 * $now->diffInMinutes($at, true)) * $row['disk_total_bytes']),
                        'php_memory_peak_bytes' => mt_rand(36, 96) * 1024 * 1024,
                        'sampled_at' => $at->format('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
    }
}
