<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Commands\Models\CommandRun;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Database\Seeders\Support\ShowcaseCatalog;
use Database\Seeders\Support\ShowcaseIssueCatalog;
use Illuminate\Database\Seeder;

/**
 * The background work of the showcase projects over the last week: job runs (with
 * failures, retries and a few that hang), queue sizes sampled each minute with one
 * backlog, scheduled tasks and their runs (one failing on some days, one missed), and
 * the artisan commands run by hand and on deploy. A failed run's issue is made by
 * ShowcaseIssuesSeeder, which links it. A project that has job runs is skipped.
 */
class ShowcaseJobsSeeder extends Seeder
{
    private Bulk $bulk;

    /** @var array<string, array<string, mixed>> factory-made rows to copy, by series */
    private array $templates = [];

    public function run(): void
    {
        $this->bulk = new Bulk();

        foreach (Showcase::projects() as [$project, $spec]) {
            if (JobRun::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $this->templates = [];
            $this->jobs($project, $spec);
            $this->queues($project, $spec);
            $this->bulk->flush();
            $this->schedule($project, $spec);
            $this->commands($project, $spec);
        }

        $this->bulk->flush();
    }

    /** @param  array<string, mixed>  $spec */
    private function jobs(Project $project, array $spec): void
    {
        $jobs = [];

        foreach ($spec['queues'] as $queue => $classes) {
            foreach ($classes as $class) {
                $jobs[] = [$queue, ...$class];
            }
        }

        // Quick jobs run more often than slow ones.
        $weights = array_map(fn (array $job): float => 1 / sqrt(max(50, $job[2])), $jobs);
        $now = Showcase::now()->getTimestamp();
        $shape = $spec['peak'] > 0 ? 'web' : 'worker';
        $backlog = $spec['backlog'];

        for ($ts = $now - ShowcaseCatalog::JOB_DAYS * 86400; $ts < $now; $ts += 600) {
            $mean = $spec['jobs_per_hour'] / 6 * Showcase::load(CarbonImmutable::createFromTimestamp($ts), $shape);

            for ($n = Showcase::poisson($mean); $n > 0; $n--) {
                $job = $jobs[(int) Showcase::pick($weights)];
                $queuedAt = $ts * 1000 + mt_rand(0, 599_999);
                $wait = $backlog !== null && $job[0] === $backlog['queue'] ? $this->backlogAge($backlog, $queuedAt / 1000) * 1000 : 0;
                $this->job($project, $spec, $job, $queuedAt, (int) ($wait + Showcase::latency(180, 0.8) / 1000), 1);
            }
        }
    }

    /**
     * One run of a job, and when it was released the attempt after it.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, mixed>  $job  queue, class, median ms, failure share, 'stuck'
     */
    private function job(Project $project, array $spec, array $job, int $queuedMs, int $waitMs, int $attempt, ?string $uuid = null): void
    {
        [$queue, $class, $median, $failure] = $job;
        $startedMs = $queuedMs + $waitMs;

        if ($startedMs > Showcase::now()->getTimestamp() * 1000) {
            return;
        }

        $timeout = $median > 30_000 ? 900 : 60;
        // Stuck means past its timeout and the grace after it (see MarkStuckJobsAction).
        $overdue = $startedMs < (Showcase::now()->getTimestamp() - $timeout - 120) * 1000;
        $status = match (true) {
            ($job[4] ?? null) === 'stuck' && $overdue && Showcase::chance(0.04) => 'stuck',
            $attempt === 1 && Showcase::chance(0.003) => 'released',
            Showcase::chance($this->failure($spec, $class, $queue, (float) $failure, $startedMs / 1000)) => 'failed',
            default => 'processed',
        };
        $us = $status === 'stuck' ? null : Showcase::latency($median, 0.6);
        $finishedMs = $us !== null ? $startedMs + intdiv($us, 1000) : null;

        if ($finishedMs !== null && $finishedMs > Showcase::now()->getTimestamp() * 1000) {
            return;
        }

        $row = $this->template("job|{$class}|{$status}", function () use ($project, $queue, $class, $status) {
            $factory = JobRun::factory()->for($project)->forQueue($queue, $class);

            return Bulk::row(match ($status) {
                'failed' => $factory->failed(),
                'released' => $factory->released(),
                'stuck' => $factory->stuck(),
                default => $factory,
            }, ['environment' => 'production']);
        });
        $updatedMs = $finishedMs ?? $startedMs + ($timeout + 120) * 1000;
        $uuid ??= Showcase::uuid();

        $this->bulk->push('job_runs', [...$row,
            'job_uuid' => $uuid,
            'trace_id' => Showcase::traceId(),
            'host' => Showcase::any($spec['workers']['production']),
            'attempt' => $attempt,
            'max_tries' => 3,
            'timeout' => $timeout,
            'parameters' => json_encode($this->parameters($class)),
            'queued_at' => $this->ms($queuedMs),
            'started_at' => $this->ms($startedMs),
            'finished_at' => $finishedMs !== null ? $this->ms($finishedMs) : null,
            'duration_us' => $us,
            'sample_rate' => 1.0,
            'sampled' => true,
            'created_at' => $this->ms($startedMs + 1),
            'updated_at' => $this->ms($updatedMs),
        ]);

        // Released jobs come back after a backoff and usually make it.
        if ($status === 'released') {
            $this->job($project, $spec, [$queue, $class, $median, $failure * 3], $updatedMs + 30_000, mt_rand(200, 2_000), $attempt + 1, $uuid);
        }
    }

    /**
     * How likely a run fails at a time: not after its issue was fixed, far more while its
     * queue is backed up (the carrier API timing out is why).
     *
     * @param  array<string, mixed>  $spec
     */
    private function failure(array $spec, string $class, string $queue, float $failure, float $ts): float
    {
        foreach (ShowcaseIssueCatalog::issues()[$spec['slug']] ?? [] as $issue) {
            if (($issue['job'] ?? null) === $class && isset($issue['until']) && $ts > Showcase::daysAgo($issue['until'])->getTimestamp()) {
                return 0;
            }
        }

        $backlog = $spec['backlog'];

        return $backlog !== null && $backlog['queue'] === $queue && $this->backlogAge($backlog, $ts) > 0 ? min(0.5, $failure * 12) : $failure;
    }

    /**
     * The queues' sizes: each minute for the last day, every ten minutes before.
     *
     * @param  array<string, mixed>  $spec
     */
    private function queues(Project $project, array $spec): void
    {
        $now = Showcase::now()->getTimestamp();
        $host = $spec['workers']['production'][0];

        foreach (array_keys($spec['queues']) as $queue) {
            $row = Bulk::row(QueueSample::factory()->for($project)->forQueue((string) $queue), ['host' => $host]);
            $busy = $spec['jobs_per_hour'] / max(1, count($spec['queues'])) / 60;

            for ($ts = $now - ShowcaseCatalog::JOB_DAYS * 86400; $ts <= $now; $ts += $ts < $now - 86400 ? 600 : 60) {
                $size = Showcase::poisson($busy * 0.6 * Showcase::load(CarbonImmutable::createFromTimestamp($ts), 'worker'));
                $age = $size > 0 ? mt_rand(1, 20) : null;

                if ($spec['backlog'] !== null && $spec['backlog']['queue'] === $queue && ($backlogAge = $this->backlogAge($spec['backlog'], $ts)) > 0) {
                    $age = $backlogAge;
                    $size = (int) ($backlogAge * 1.7) + mt_rand(0, 40);
                }

                $this->bulk->push('queue_samples', [...$row, 'size' => $size, 'oldest_age_s' => $age, 'sampled_at' => date('Y-m-d H:i:s', $ts)]);
            }
        }
    }

    /**
     * How long the oldest job of the backed-up queue had waited, in seconds: it builds up
     * for most of the episode while the carrier API is slow, then drains in the last hour.
     *
     * @param  array{queue: string, start: float, hours: int}  $backlog
     */
    private function backlogAge(array $backlog, float $ts): int
    {
        $start = Showcase::daysAgo($backlog['start'])->getTimestamp();
        $length = $backlog['hours'] * 3600;
        $into = $ts - $start;

        if ($into < 0 || $into > $length) {
            return 0;
        }

        $peak = 2_700;
        $drainFrom = $length - 3600;

        return (int) ($into < $drainFrom ? $peak * $into / $drainFrom : $peak * (1 - ($into - $drainFrom) / 3600));
    }

    /** @param  array<string, mixed>  $spec */
    private function schedule(Project $project, array $spec): void
    {
        $now = Showcase::now();
        $from = $now->subDays(ShowcaseCatalog::JOB_DAYS);

        foreach ($spec['schedule'] as $entry) {
            [$name, $expression, $median, $options] = $entry + [3 => []];
            $cron = new CronExpression($expression);
            $task = ScheduledTask::factory()->for($project)->cron($name, $expression)->create(['created_at' => $now->subDays(40)]);
            $template = Bulk::row(ScheduledTaskRun::factory(), ['project_id' => $project->id, 'scheduled_task_id' => $task->id, 'environment' => 'production']);
            $failed = Bulk::row(ScheduledTaskRun::factory()->failed(), ['project_id' => $project->id, 'scheduled_task_id' => $task->id, 'environment' => 'production']);
            $missed = Bulk::row(ScheduledTaskRun::factory()->missed(), ['project_id' => $project->id, 'scheduled_task_id' => $task->id, 'environment' => 'production']);
            $last = null;
            $due = CarbonImmutable::instance($cron->getNextRunDate($from->toDateTimeImmutable()));
            $next = CarbonImmutable::instance($cron->getNextRunDate($now->toDateTimeImmutable()));
            $previous = CarbonImmutable::instance($cron->getPreviousRunDate($now->toDateTimeImmutable(), 0, true));

            while ($due < $now) {
                $daysAgo = (int) floor($due->diffInHours($now, true) / 24);
                // A failing day fails for a morning, not all day.
                $failing = in_array($daysAgo, $options['fail_days'] ?? [], true) && ($due->hour >= 6 && $due->hour < 10 || substr_count($expression, '*') < 3);
                $isMissed = ($options['missed'] ?? false) && $due->eq($previous);
                $us = Showcase::latency($median, 0.35);
                $startedMs = $due->getTimestampMs() + mt_rand(5, 400);
                $row = $isMissed ? $missed : ($failing ? $failed : $template);

                // Still running: the next heartbeat will tell.
                if (! $isMissed && $startedMs + intdiv($us, 1000) > $now->getTimestampMs()) {
                    break;
                }

                $this->bulk->push('scheduled_task_runs', [...$row,
                    'run_uuid' => Showcase::uuid(),
                    'trace_id' => $isMissed ? null : Showcase::traceId(),
                    'host' => $isMissed ? null : $spec['workers']['production'][0],
                    'started_at' => $isMissed ? Showcase::ms($due) : $this->ms($startedMs),
                    'finished_at' => $isMissed ? null : $this->ms($startedMs + intdiv($us, 1000)),
                    'duration_us' => $isMissed ? null : $us,
                    'sample_rate' => 1.0,
                    'sampled' => true,
                    'created_at' => $this->ms($startedMs + intdiv($us, 1000) + 50),
                    'updated_at' => $this->ms($startedMs + intdiv($us, 1000) + 50),
                ]);

                $last = $isMissed ? $last : [$failing ? ScheduledRunStatus::Failed : ScheduledRunStatus::Finished, $due, $us];
                $due = CarbonImmutable::instance($cron->getNextRunDate($due->toDateTimeImmutable()));
            }

            $isMissed = $options['missed'] ?? false;
            $task->forceFill([
                'last_status' => $isMissed ? ScheduledRunStatus::Missed : $last[0] ?? ScheduledRunStatus::Finished,
                'last_run_at' => $last[1] ?? null,
                'last_duration_us' => $last[2] ?? null,
                'next_run_at' => $next,
                'missed_at' => $isMissed ? $previous : null,
                'last_seen_at' => $last[1] ?? $now,
            ])->save();
        }
    }

    /**
     * Each deploy runs the project's first commands; the rest are run by hand now and then.
     *
     * @param  array<string, mixed>  $spec
     */
    private function commands(Project $project, array $spec): void
    {
        $now = Showcase::now();
        $runs = [];

        foreach ($spec['releases'] as $release) {
            if ($release[1] <= ShowcaseCatalog::JOB_DAYS) {
                $deploy = Showcase::daysAgo($release[1]);
                $runs[] = [$spec['commands'][0], $deploy->subSeconds(40)];

                if (isset($spec['commands'][1]) && $spec['commands'][1][0] === 'optimize') {
                    $runs[] = [$spec['commands'][1], $deploy->subSeconds(25)];
                }
            }
        }

        foreach (array_slice($spec['commands'], 1) as $command) {
            for ($n = mt_rand(2, 5); $n > 0; $n--) {
                $runs[] = [$command, $now->subMinutes(mt_rand(30, ShowcaseCatalog::JOB_DAYS * 1440))];
            }
        }

        foreach ($runs as [[$name, $arguments], $at]) {
            $options = array_filter($arguments, fn (string $key): bool => str_starts_with($key, '--'), ARRAY_FILTER_USE_KEY);
            $positional = array_diff_key($arguments, $options);
            // The import breaks on a malformed row now and then.
            $failed = $name === 'catalog:import' && Showcase::chance(0.5) || $name === 'queue:retry' && Showcase::chance(0.3);
            $factory = CommandRun::factory()->for($project)->command($name, $positional, array_combine(array_map(fn (string $k): string => substr($k, 2), array_keys($options)), $options) ?: []);
            $us = Showcase::latency(str_contains($name, 'import') || str_contains($name, 'recount') ? 45_000 : 1_800, 0.5);

            $this->bulk->add($failed ? $factory->failed() : $factory, [
                'run_uuid' => Showcase::uuid(),
                'trace_id' => Showcase::traceId(),
                'host' => $spec['workers']['production'][0],
                'environment' => 'production',
                'started_at' => $at,
                'finished_at' => $at->addMicroseconds($us),
                'duration_us' => $us,
                'created_at' => $at->addMicroseconds($us)->addSecond(),
                'updated_at' => $at->addMicroseconds($us)->addSecond(),
            ]);
        }
    }

    /**
     * What the job was dispatched with, as the SDK records it.
     *
     * @return array<string, mixed>
     */
    private function parameters(string $class): array
    {
        $id = mt_rand(10_000, 99_999);

        return match (class_basename($class)) {
            'OrderConfirmation', 'AllocateStock', 'PrintShippingLabel', 'RecalculateTotals' => ['orderId' => $id],
            'DeliverWebhook' => ['endpointId' => mt_rand(10, 400), 'event' => Showcase::any(['order.created', 'order.updated', 'order.shipped'])],
            'BuildOrderExport' => ['exportId' => $id, 'format' => 'csv'],
            'ImportSupplierFeed' => ['supplier' => Showcase::any(['nordic-wood', 'lampworks', 'textile-co'])],
            'HandleStripeWebhook' => ['eventId' => 'evt_'.substr(Showcase::traceId(), 0, 24)],
            'SyncInvoicesFromErp' => ['since' => Showcase::now()->subHour()->toIso8601String()],
            default => [],
        };
    }

    private function ms(int|float $epochMs): string
    {
        return CarbonImmutable::createFromTimestampMs((int) $epochMs)->format('Y-m-d H:i:s.v');
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
