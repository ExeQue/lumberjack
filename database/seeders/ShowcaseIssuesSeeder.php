<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Enums\WatchReason;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Models\IssueWatcher;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Issues\Support\Fingerprinter;
use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Database\Seeders\Support\ShowcaseIssueCatalog;
use Database\Seeders\Support\ShowcaseStack;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The showcase issues, each with its occurrences over time and what the team did:
 * assigned, labelled, noted (with mentions), resolved, ignored on a condition, back
 * again. Counters, numbers, fingerprints and the first and last release match the
 * occurrences, as RecordOccurrenceAction keeps them. A failed job, scheduled task or
 * command run is linked to the occurrence it made. A project with issues is skipped.
 */
class ShowcaseIssuesSeeder extends Seeder
{
    /** How many of an issue's latest occurrences get a full trace: request, queries. */
    private const int TRACED = 3;

    private Bulk $bulk;

    /** @var array<string, User> */
    private array $people = [];

    private ?McpToken $bot = null;

    public function __construct(private readonly Fingerprinter $fingerprinter)
    {
    }

    public function run(): void
    {
        $this->bulk = new Bulk();

        foreach (Showcase::projects() as [$project, $spec]) {
            if (Issue::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $this->people = User::query()->whereHas('teams', fn ($q) => $q->whereKey($project->team_id))->get()->keyBy('email')->all();
            $this->bot = McpToken::query()->where('team_id', $project->team_id)->where('name', 'triage-bot')->first();

            foreach (ShowcaseIssueCatalog::issues()[$spec['slug']] ?? [] as $issue) {
                $this->issue($project, $spec, $issue);
            }

            $this->bulk->flush();
        }

        $this->bulk->flush();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $issue
     */
    private function issue(Project $project, array $spec, array $issue): void
    {
        $now = Showcase::now();
        $source = IssueSource::from($issue['source'] ?? ($issue['kind'] === 'log' ? 'log' : 'server'));
        $frames = $issue['frames'] ?? [];
        $stacktrace = ShowcaseStack::trace($frames, $issue['kind']);
        $occurrences = $this->occurrences($project, $spec, $issue);

        if ($occurrences === []) {
            return;
        }

        $first = $occurrences[0];
        $last = $occurrences[count($occurrences) - 1];
        $users = array_unique(array_filter(array_column($occurrences, 'user')));
        $signal = new IssueSignal(
            $source,
            $issue['title'],
            $issue['message'],
            $frames[0][0] ?? null,
            $frames[0][1] ?? null,
            $stacktrace,
            [],
            $first['at'],
            logChannel: $issue['log_channel'] ?? null,
            customFingerprint: $this->customFingerprint($issue)
        );

        $state = $this->state($issue, $occurrences);
        $factory = Issue::factory()->for($project)
            ->when($source === IssueSource::Browser)->fromBrowser()
            ->when($source === IssueSource::Log)->fromLog($issue['log_channel'] ?? 'default')
            ->when(isset($issue['labels']))->labelled(...($issue['labels'] ?? []));
        $factory = $this->applyState($factory, $issue, $state, count($occurrences));

        if (isset($issue['assignee'], $this->people[$issue['assignee']])) {
            $factory = $factory->assignedTo($this->people[$issue['assignee']]);
        }

        $model = $factory->createOne([
            'fingerprint' => $this->fingerprinter->fingerprint($signal),
            'title' => $issue['title'],
            'message' => $issue['message'],
            'file' => $source === IssueSource::Log ? null : ($frames[0][0] ?? null),
            'line' => $source === IssueSource::Log ? null : ($frames[0][1] ?? null),
            'occurrences_count' => count($occurrences),
            'users_count' => count($users),
            'first_seen_at' => $first['at'],
            'last_seen_at' => $last['at'],
            'first_release' => $first['release'],
            'last_release' => $last['release'] ?? $first['release'],
            'first_environment' => 'production',
            'external_issue_provider' => isset($issue['repo']) ? 'github' : null,
            'external_issue_url' => $issue['repo'] ?? null,
            'created_at' => $first['at'],
            'updated_at' => $last['at'],
            ...$state['attributes'],
        ]);

        $template = Bulk::row(Occurrence::factory()->for($model), ['stacktrace' => $stacktrace, 'environment' => 'production']);

        foreach ($occurrences as $occurrence) {
            $this->bulk->push('occurrences', [...$template,
                'trace_id' => $occurrence['trace'],
                'release' => $occurrence['release'],
                'host' => $occurrence['host'],
                'user_id' => $occurrence['user'],
                'context' => json_encode($this->context($spec, $issue, $occurrence)),
                'occurred_at' => Showcase::ms($occurrence['at']),
            ]);
        }

        $this->bulk->flush();
        $this->link($project, $issue, $model);
        $this->traces($project, $spec, $issue, array_slice($occurrences, -self::TRACED));
        $this->logLines($project, $issue, $occurrences);
        $this->history($model, $issue, $state, $first['at']);
    }

    /**
     * When it happened, where, in which trace and to whom.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $issue
     * @return list<array{at: CarbonImmutable, trace: string|null, host: string|null, release: string|null, user: string|null}>
     */
    private function occurrences(Project $project, array $spec, array $issue): array
    {
        $runs = $this->failedRuns($project, $issue);
        $now = Showcase::now();

        if ($runs !== null) {
            $times = $runs;
        } else {
            $times = match ($issue['state']) {
                'bad_release' => $this->badReleaseTimes($spec),
                'escalating' => $this->burst($now->subMinutes(26), 74),
                'regressed' => [...$this->spread(Showcase::daysAgo($issue['from']), Showcase::daysAgo($issue['resolved']), $issue['daily']),
                    ...$this->spread(Showcase::daysAgo($issue['back']), $now, $issue['daily'] * 1.4)],
                default => $this->spread(Showcase::daysAgo($issue['from']), isset($issue['until']) ? Showcase::daysAgo($issue['until']) : $now, $issue['daily']),
            };
            $times = array_map(fn (CarbonImmutable $at): array => ['at' => $at, 'trace' => Showcase::traceId(), 'host' => null], $times);
        }

        usort($times, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $hosts = $spec['hosts']['production'];
        $customers = $issue['kind'] === 'http' || $issue['kind'] === 'browser' ? max(3, (int) (count($times) * 0.6)) : 0;

        return array_map(fn (array $time): array => [
            'at' => $time['at'],
            'trace' => $time['trace'],
            'host' => $issue['kind'] === 'browser' ? null : ($time['host'] ?? Showcase::any($hosts)),
            'release' => Showcase::release($spec, $time['at']),
            'user' => $customers > 0 && Showcase::chance($issue['users'] ?? 0) ? (string) (40_000 + mt_rand(1, $customers)) : null,
        ], $times);
    }

    /**
     * The failures of the runs the issue stands for, or null for an issue of its own.
     *
     * @param  array<string, mixed>  $issue
     * @return list<array{at: CarbonImmutable, trace: string|null, host: string|null}>|null
     */
    private function failedRuns(Project $project, array $issue): ?array
    {
        $query = match (true) {
            isset($issue['job']) => DB::table('job_runs')->where('name', $issue['job'])->where('status', 'failed')->select(['finished_at as at', 'trace_id', 'host']),
            isset($issue['task']) => $this->taskRuns($issue['task'], 'failed')->select(['scheduled_task_runs.finished_at as at', 'scheduled_task_runs.trace_id', 'scheduled_task_runs.host']),
            isset($issue['missed']) => $this->taskRuns($issue['missed'], 'missed')->select(['scheduled_task_runs.started_at as at', 'scheduled_task_runs.trace_id', 'scheduled_task_runs.host']),
            isset($issue['command']) => DB::table('command_runs')->where('name', $issue['command'])->where('status', 'failed')->select(['finished_at as at', 'trace_id', 'host']),
            default => null,
        };

        $runs = $query?->where(isset($issue['task']) || isset($issue['missed']) ? 'scheduled_task_runs.project_id' : 'project_id', $project->id)->get()
            ->map(fn (object $run): array => ['at' => CarbonImmutable::parse($run->at), 'trace' => $run->trace_id !== null ? (string) $run->trace_id : null, 'host' => $run->host !== null ? (string) $run->host : null])
            ->values()->all();

        return $runs === null ? null : array_values($runs);
    }

    private function taskRuns(string $task, string $status): \Illuminate\Database\Query\Builder
    {
        return DB::table('scheduled_task_runs')
            ->join('scheduled_tasks', 'scheduled_tasks.id', '=', 'scheduled_task_runs.scheduled_task_id')
            ->where('scheduled_tasks.name', $task)->where('scheduled_task_runs.status', $status);
    }

    /**
     * Sets each failed run's occurrence, by the trace they share.
     *
     * @param  array<string, mixed>  $issue
     */
    private function link(Project $project, array $issue, Issue $model): void
    {
        $table = match (true) {
            isset($issue['job']) => 'job_runs',
            isset($issue['task']), isset($issue['missed']) => 'scheduled_task_runs',
            isset($issue['command']) => 'command_runs',
            default => null,
        };

        if ($table === null) {
            return;
        }

        if (isset($issue['missed'])) {
            // A missed run has no trace: it is the one occurrence of its due time.
            DB::update("UPDATE scheduled_task_runs r SET occurrence_id = o.id FROM occurrences o
                WHERE o.issue_id = ? AND r.project_id = ? AND r.status = 'missed' AND r.started_at = o.occurred_at", [$model->id, $project->id]);

            return;
        }

        DB::update("UPDATE {$table} r SET occurrence_id = o.id FROM occurrences o WHERE o.issue_id = ? AND r.project_id = ? AND r.trace_id = o.trace_id", [$model->id, $project->id]);
    }

    /**
     * The requests behind the latest occurrences, with their queries, so the trace page has them.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $occurrences
     */
    private function traces(Project $project, array $spec, array $issue, array $occurrences): void
    {
        if ($issue['kind'] !== 'http' || ! isset($issue['route'])) {
            return;
        }

        [$method, $route] = $issue['route'];
        $name = null;

        foreach ($spec['routes'] as $candidate) {
            $name = $candidate[0] === $method && $candidate[1] === $route ? $candidate[2] : $name;
        }

        $status = match (true) {
            str_contains($issue['title'], 'ModelNotFound') => 404,
            str_contains($issue['title'], 'CardException') => 402,
            str_contains($issue['title'], 'Coupon') => 422,
            str_contains($issue['title'], 'OAuthServer') => 401,
            default => 500,
        };

        foreach ($occurrences as $occurrence) {
            $start = $occurrence['at']->subMilliseconds(mt_rand(150, 900));
            $offset = 15_000;

            $this->bulk->add(RequestRun::factory()->for($project)->onDomain($spec['domain'] ?? '')->forRoute($method, $route, $name)->sampledAt(1.0), [
                'trace_id' => $occurrence['trace'],
                'environment' => 'production',
                'release' => $occurrence['release'],
                'host' => $occurrence['host'],
                'status_code' => $status,
                'duration_us' => (int) $start->diffInMicroseconds($occurrence['at']) + mt_rand(5_000, 40_000),
                'memory_peak' => mt_rand(24, 48) * 1024 * 1024,
                'occurred_at' => $start,
            ]);

            foreach ($spec['queries'][$method.' '.$route] ?? $spec['queries'][$route] ?? [] as $query) {
                $duration = Showcase::latency($query[2], 0.3);
                $this->bulk->add(QueryRun::factory()->for($project)->forSql($query[0], $query[1])->inTrace($occurrence['trace']), [
                    'environment' => 'production',
                    'connection' => 'pgsql',
                    'duration_us' => $duration,
                    'occurred_at' => $start->addMicroseconds($offset),
                    'sample_rate' => 1.0,
                    'sampled' => true,
                ]);
                $offset += $duration + 400;
            }

            $this->bulk->add(LogEntry::factory()->state(['project_id' => $project->id, 'log_channel_id' => $this->channel($project, 'default')]), [
                'level' => LogLevel::Error,
                'message' => $issue['title'].': '.$issue['message'],
                'context' => ['exception' => $issue['title'], 'route' => $route],
                'trace_id' => $occurrence['trace'],
                'environment' => 'production',
                'release' => $occurrence['release'],
                'host' => $occurrence['host'],
                'occurred_at' => $occurrence['at'],
            ]);
        }
    }

    /**
     * The log lines an issue from a log channel was grouped from, one per occurrence.
     *
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $occurrences
     */
    private function logLines(Project $project, array $issue, array $occurrences): void
    {
        if ($issue['kind'] !== 'log') {
            return;
        }

        $row = Bulk::row(LogEntry::factory()->state(['project_id' => $project->id, 'log_channel_id' => $this->channel($project, $issue['log_channel'] ?? 'default')]), [
            'level' => LogLevel::Error,
            'message' => $issue['message'],
            'environment' => 'production',
        ]);

        foreach ($occurrences as $occurrence) {
            $this->bulk->push('log_entries', [...$row,
                'context' => json_encode(['endpoint' => $issue['route'][1] ?? null, 'ip' => '54.187.'.mt_rand(1, 250).'.'.mt_rand(1, 250)]),
                'trace_id' => $occurrence['trace'],
                'release' => $occurrence['release'],
                'host' => $occurrence['host'],
                'occurred_at' => Showcase::ms($occurrence['at']),
            ]);
        }
    }

    /**
     * The status, timestamps and events of the issue's story.
     *
     * @param  array<string, mixed>  $issue
     * @param  list<array<string, mixed>>  $occurrences
     * @return array{attributes: array<string, mixed>, events: list<array{0: CarbonImmutable, 1: string, 2: mixed, 3: string|null}>}
     */
    private function state(array $issue, array $occurrences): array
    {
        $now = Showcase::now();
        $first = $occurrences[0]['at'];
        $last = $occurrences[count($occurrences) - 1]['at'];
        $assignee = $issue['assignee'] ?? null;
        $events = [];
        $attributes = ['surfaced_at' => $first];

        if ($assignee !== null) {
            $events[] = [$this->min($first->addMinutes(35), $now), 'assigned', [null, $this->people[$assignee]->name ?? null], 'admin@lumberjack'];
        }

        switch ($issue['state']) {
            case 'in_progress':
                $at = $this->min($first->addHours(3), $now->subMinutes(30));
                $attributes['in_progress_at'] = $at;
                $events[] = [$at, 'status', [IssueStatus::Open, IssueStatus::InProgress], $assignee];
                break;
            case 'resolved':
            case 'bad_release':
                $at = $this->min($last->addMinutes(50), $now);
                $attributes['resolved_at'] = $at;
                $events[] = [$at, 'status', [IssueStatus::Open, IssueStatus::Resolved], $assignee];
                break;
            case 'ignored':
                // Recently enough that a time condition has not run out yet.
                $at = $this->max($first->addHours(1), $now->subDays(3));
                $attributes['ignored_at'] = $at;
                $events[] = [$at, 'status', [IssueStatus::Open, IssueStatus::Ignored], 'maja@lumberjack'];
                break;
            case 'regressed':
                $resolved = Showcase::daysAgo($issue['resolved'])->addHours(2);
                $back = collect($occurrences)->first(fn (array $o): bool => $o['at'] > $resolved);
                $attributes['regressed_at'] = $back['at'];
                $attributes['surfaced_at'] = $back['at'];
                $events[] = [$resolved, 'status', [IssueStatus::Open, IssueStatus::Resolved], $assignee];
                $events[] = [$back['at'], 'regressed', $back['release'], null];
                break;
            case 'returned':
                $ignored = Showcase::daysAgo($issue['ignored']);
                $back = Showcase::daysAgo($issue['back']);
                $between = count(array_filter($occurrences, fn (array $o): bool => $o['at'] > $ignored && $o['at'] <= $back));
                $attributes['returned_at'] = $back;
                $attributes['surfaced_at'] = $back;
                $events[] = [$ignored, 'status', [IssueStatus::Open, IssueStatus::Ignored], 'oskar@lumberjack'];
                $events[] = [$back, 'returned', max(1, $between), null];
                break;
            case 'escalating':
                $attributes['escalated_at'] = $first->addMinutes(6);
                $attributes['surfaced_at'] = $first->addMinutes(6);
                $events[] = [$first->addMinutes(6), 'escalated', count(array_filter($occurrences, fn (array $o): bool => $o['at'] <= $first->addMinutes(6))), null];
                break;
        }

        if ($issue['state'] === 'bad_release') {
            $events[] = [$first->addMinutes(9), 'escalated', 38, null];
            $attributes['escalated_at'] = $first->addMinutes(9);
            $attributes['surfaced_at'] = $first->addMinutes(9);
        }

        if (isset($issue['labels'])) {
            $events[] = [$this->min($first->addMinutes(40), $now), 'labels', $issue['labels'], $assignee ?? 'admin@lumberjack'];
        }

        if (isset($issue['repo'])) {
            $events[] = [$this->min($first->addMinutes(55), $now), 'repo', $issue['repo'], $assignee];
        }

        return ['attributes' => $attributes, 'events' => $events];
    }

    /**
     * The factory state for where the issue stands now.
     *
     * @param  array<string, mixed>  $issue
     * @param  array{attributes: array<string, mixed>}  $state
     */
    private function applyState(IssueFactory $factory, array $issue, array $state, int $count): IssueFactory
    {
        $a = $state['attributes'];

        return match ($issue['state']) {
            'in_progress' => $factory->inProgress($a['in_progress_at']),
            'resolved', 'bad_release' => $factory->resolved(),
            'ignored' => match ($issue['ignore'] ?? 'forever') {
                '7d' => $factory->ignoredUntil($a['ignored_at']->addDays(7)),
                'escalation' => $factory->ignoredUntil(escalation: true),
                'count' => $factory->ignoredUntil(count: $count + 500),
                default => $factory->ignoredUntil(),
            },
            'regressed' => $factory->regressed($a['regressed_at']),
            'returned' => $factory->returned($a['returned_at']),
            'escalating' => $factory->escalating($a['escalated_at']),
            default => $factory,
        };
    }

    /**
     * Events, notes and watchers, as the actions that change an issue record them.
     *
     * @param  array<string, mixed>  $issue
     * @param  array{events: list<array{0: CarbonImmutable, 1: string, 2: mixed, 3: string|null}>}  $state
     */
    private function history(Issue $model, array $issue, array $state, CarbonImmutable $first): void
    {
        IssueEvent::factory()->for($model)->create(['occurred_at' => $first]);

        foreach ($state['events'] as [$at, $type, $data, $actor]) {
            $factory = match ($type) {
                'assigned' => IssueEvent::factory()->assigned(...$data),
                'status' => IssueEvent::factory()->statusChanged(...$data),
                'regressed' => IssueEvent::factory()->regressed($data),
                'returned' => IssueEvent::factory()->returned($data),
                'escalated' => IssueEvent::factory()->escalated($data),
                'labels' => IssueEvent::factory()->labelsChanged($data),
                default => IssueEvent::factory()->repoIssueCreated($data),
            };
            $factory->for($model)->create(['occurred_at' => $at, 'user_id' => $actor !== null ? ($this->people[$actor]->id ?? null) : null]);
        }

        $watchers = [];

        if ($model->assigned_user_id !== null) {
            $watchers[$model->assigned_user_id] = WatchReason::Assigned;
        }

        foreach ($issue['notes'] ?? [] as $i => [$author, $body]) {
            $user = $this->people[$author] ?? null;
            $at = $this->min($first->addMinutes(45 + 70 * $i), Showcase::now()->subMinutes(5 * (count($issue['notes']) - $i)));
            $body = preg_replace_callback('/<@([^>]+@lumberjack)>/', function (array $m) use (&$watchers): string {
                $mentioned = $this->people[$m[1]] ?? null;

                if ($mentioned === null) {
                    return '@'.explode('@', $m[1])[0];
                }

                $watchers[$mentioned->id] ??= WatchReason::Mentioned;

                return '<@'.$mentioned->id.'>';
            }, $body) ?? $body;

            if ($user === null && ($author !== 'triage-bot' || $this->bot === null)) {
                continue;
            }

            IssueNote::factory()->for($model)->create([
                'user_id' => $user !== null ? $user->id : $this->bot->user_id,
                'mcp_token_id' => $user === null ? $this->bot->id : null,
                'body' => $body,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            if ($user !== null) {
                $watchers[$user->id] ??= WatchReason::Noted;
            }
        }

        foreach ($issue['watchers'] ?? [] as $email) {
            if (isset($this->people[$email])) {
                $watchers[$this->people[$email]->id] ??= WatchReason::Manual;
            }
        }

        foreach ($watchers as $userId => $reason) {
            IssueWatcher::factory()->for($model)->because($reason)->create(['user_id' => $userId, 'created_at' => $first->addHour()]);
        }
    }

    /**
     * What the SDK sent along: the request and who was signed in, or the job.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $issue
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>
     */
    private function context(array $spec, array $issue, array $occurrence): array
    {
        $context = match ($issue['kind']) {
            'http', 'browser', 'log' => ['request' => [
                'method' => $issue['route'][0] ?? 'GET',
                'url' => 'https://'.($spec['domain'] ?? 'example.com').str_replace(['{slug}', '{order}', '{room}', '{invoice}'], ['oak-desk-lamp', (string) mt_rand(90_000, 99_999), (string) mt_rand(1, 40), 'INV-'.mt_rand(2000, 2999)], $issue['route'][1] ?? '/'),
                'route' => $issue['route'][1] ?? '/',
                'ip' => '203.0.113.'.mt_rand(2, 250),
                'headers' => [
                    'user-agent' => Showcase::any([
                        'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
                        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
                    ]),
                    'accept-language' => Showcase::any(['da-DK,da;q=0.9,en;q=0.8', 'en-US,en;q=0.9', 'sv-SE,sv;q=0.9']),
                ],
            ]],
            'queue' => ['job' => ['name' => $issue['job'] ?? null, 'queue' => $this->queueOf($spec, $issue['job'] ?? ''), 'connection' => 'redis']],
            default => ['schedule' => ['task' => $issue['task'] ?? $issue['missed'] ?? $issue['command'] ?? null]],
        };

        if ($occurrence['user'] !== null) {
            $context['user'] = ['id' => $occurrence['user']];
        }

        return $context;
    }

    /** @param  array<string, mixed>  $spec */
    private function queueOf(array $spec, string $job): ?string
    {
        foreach ($spec['queues'] as $queue => $jobs) {
            if (in_array($job, array_column($jobs, 0), true)) {
                return $queue;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $issue */
    private function customFingerprint(array $issue): ?string
    {
        return match (true) {
            isset($issue['task']) => 'schedule-failed:production:'.$issue['task'],
            isset($issue['missed']) => 'schedule-missed:production:'.$issue['missed'],
            isset($issue['command']) => 'command-failed:production:'.$issue['command'],
            default => null,
        };
    }

    /**
     * The bad release's errors: from a few minutes after the deploy until the rollback,
     * following the checkout traffic.
     *
     * @param  array<string, mixed>  $spec
     * @return list<CarbonImmutable>
     */
    private function badReleaseTimes(array $spec): array
    {
        [$from, $to] = Showcase::badRelease($spec) ?? [Showcase::now()->subDay(), Showcase::now()];

        return $this->spread($from->addMinutes(3), $to, 1_600);
    }

    /**
     * A sudden burst: this many in the last minutes, most of them early.
     *
     * @return list<CarbonImmutable>
     */
    private function burst(CarbonImmutable $from, int $count): array
    {
        $span = (int) $from->diffInSeconds(Showcase::now(), true);

        return array_map(fn (): CarbonImmutable => $from->addSeconds((int) ($span * Showcase::unit() ** 1.6)), range(1, $count));
    }

    /**
     * Times between two moments, about $daily a day, more of them when the project is busy.
     *
     * @return list<CarbonImmutable>
     */
    private function spread(CarbonImmutable $from, CarbonImmutable $to, float $daily): array
    {
        $times = [];

        for ($hour = $from; $hour < $to; $hour = $hour->addHour()) {
            for ($n = Showcase::poisson($daily / 24 * 2 * Showcase::load($hour)); $n > 0; $n--) {
                $at = $hour->addSeconds(mt_rand(0, 3599));

                if ($at < $to) {
                    $times[] = $at;
                }
            }
        }

        return $times === [] ? [$this->min($from->addMinutes(10), $to)] : $times;
    }

    private function channel(Project $project, string $name): int
    {
        return (int) LogChannel::query()->where('project_id', $project->id)->where('name', $name)->value('id');
    }

    private function min(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a < $b ? $a : $b;
    }

    private function max(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a > $b ? $a : $b;
    }
}
