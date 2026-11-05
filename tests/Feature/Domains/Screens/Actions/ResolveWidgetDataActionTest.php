<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Support\Prometheus;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\Screens\Actions\ResolveWidgetDataAction;
use App\Domains\Screens\Data\WidgetScope;
use App\Domains\Screens\Data\WidgetSource;
use App\Domains\Screens\Enums\ReleaseScope;
use App\Domains\Screens\Enums\WidgetType;
use App\Domains\Screens\Exceptions\UnknownWidgetSourceException;
use App\Domains\Screens\Support\WidgetSources;
use App\Domains\Shared\Enums\Period;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Health\CreatesDatabaseHealthResults;
use Tests\Support\Performance\CreatesRequestCounts;

covers(ResolveWidgetDataAction::class, WidgetSources::class, WidgetScope::class, WidgetSource::class);

uses(CreatesRequestCounts::class, CreatesDatabaseHealthResults::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0, 30));
    $this->project = Project::factory()->create();
    $this->resolve = fn (WidgetType $type, string $source, array $options = [], ?WidgetScope $scope = null): array => app(ResolveWidgetDataAction::class)
        ->execute($this->project->team, $type, $source, $scope ?? new WidgetScope(), $options);
});

describe('the registry', function () {
    it('resolves every source it lists, even without data', function (WidgetType $type, string $source) {
        $data = ($this->resolve)($type, $source);

        expect($data)->toMatchArray(['type' => $type->value, 'source' => $source])
            ->and(json_encode($data))->toBeString()
            ->and(__(WidgetSources::find($type, $source)->label()))->not->toStartWith('screens.');
    })->with(fn (): array => collect(WidgetSources::all())->flatMap(fn (array $sources, string $type): array => collect($sources)->mapWithKeys(fn ($s, string $id): array => ["{$type} {$id}" => [WidgetType::from($type), $id]])->all())->all());

    it('has sources for every data visualization', function (WidgetType $type) {
        expect(WidgetSources::for($type))->not->toBeEmpty();
    })->with(WidgetType::cases());

    it('refuses a source its type does not have', function () {
        expect(fn () => ($this->resolve)(WidgetType::Gauge, 'latest_issues'))->toThrow(UnknownWidgetSourceException::class);
    });
});

describe('a stat', function () {
    it('shows the period with its trend, state and sparkline', function () {
        $this->createRequestCount($this->project, 30, minute: now()->subHour()->startOfMinute());
        $this->createRequestCount($this->project, 20, minute: now()->subDay()->subHour()->startOfMinute());

        $data = ($this->resolve)(WidgetType::Stat, 'requests');

        expect($data)->toMatchArray(['unit' => 'count', 'value' => 30.0, 'previous' => 20.0, 'change' => 0.5, 'state' => null])
            ->and(collect($data['sparkline'])->sum('v'))->toEqual(30);
    });

    it('colors a value past its limits, overridable per widget', function () {
        $this->createRequestCount($this->project, 97);
        $this->createRequestCount($this->project, 3, 500);

        expect(($this->resolve)(WidgetType::Stat, 'error_rate')['state'])->toBe('amber')
            ->and(($this->resolve)(WidgetType::Stat, 'error_rate', ['red' => 0.02])['state'])->toBe('red')
            ->and(($this->resolve)(WidgetType::Stat, 'error_rate', ['amber' => 0.1, 'red' => 0.2])['state'])->toBe('ok');
    });

    it('turns red below the limit where low is bad', function () {
        ProjectAgent::factory()->for($this->project)->offline()->create();

        expect(($this->resolve)(WidgetType::Stat, 'agents_online'))->toMatchArray(['value' => 0.0, 'state' => 'red']);
    });

    it('reads only the team\'s projects, and the picked ones', function () {
        $other = Project::factory()->create(['team_id' => $this->project->team_id]);
        $foreign = Project::factory()->create();
        Issue::factory()->for($this->project)->create();
        Issue::factory()->for($other)->count(2)->create();
        Issue::factory()->for($foreign)->count(5)->create();

        expect(($this->resolve)(WidgetType::Stat, 'open_issues')['value'])->toEqual(3)
            ->and(($this->resolve)(WidgetType::Stat, 'open_issues', scope: new WidgetScope(projects: [$other->uuid]))['value'])->toEqual(2)
            ->and(($this->resolve)(WidgetType::Stat, 'open_issues', scope: new WidgetScope(projects: [$foreign->uuid]))['value'])->toEqual(0);
    });

    it('covers the current release only when asked to', function () {
        RequestRun::factory()->for($this->project)->count(2)->create(['release' => 'v1', 'occurred_at' => now()->subHours(3)]);
        RequestRun::factory()->for($this->project)->count(5)->create(['release' => 'v2', 'occurred_at' => now()->subHour()]);

        $release = ($this->resolve)(WidgetType::Stat, 'current_release');
        expect($release['release'])->toHaveKeys(['since', 'previous', 'error_rate', 'error_rate_change'])
            ->and($release['release']['previous'])->toBe('v1');

        expect(($this->resolve)(WidgetType::Stat, 'current_release')['value'])->toBe('v2')
            ->and(($this->resolve)(WidgetType::Stat, 'requests', scope: new WidgetScope(release: ReleaseScope::Current))['value'])->toEqual(5)
            ->and(($this->resolve)(WidgetType::Stat, 'requests', scope: new WidgetScope(release: ReleaseScope::Previous))['value'])->toEqual(2);
    });
});

describe('queue workers', function () {
    it('adds up the latest workers of each queue, and knows none where no agent told', function () {
        expect(($this->resolve)(WidgetType::Stat, 'queue_workers')['value'])->toBeNull();

        QueueSample::factory()->forProject($this->project)->forQueue('mail')->withWorkers(5)->create(['sampled_at' => now()->subMinutes(3)]);
        QueueSample::factory()->forProject($this->project)->forQueue('mail')->withWorkers(2)->create(['sampled_at' => now()->subMinute()]);
        QueueSample::factory()->forProject($this->project)->forQueue('bulk')->withWorkers(0)->create(['sampled_at' => now()->subMinute()]);
        QueueSample::factory()->forProject($this->project)->forQueue('old')->create(['sampled_at' => now()->subMinute()]);

        expect(($this->resolve)(WidgetType::Stat, 'queue_workers'))->toMatchArray(['value' => 2.0, 'state' => 'ok'])
            ->and(($this->resolve)(WidgetType::Stat, 'queue_workers', scope: new WidgetScope(filter: 'bulk'))['state'])->toBe('red');
    });
});

describe('a gauge, a chart and a list', function () {
    it('puts a share on a scale from 0 to 1', function () {
        QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'mail', 'size' => 150, 'sampled_at' => now()->subMinute()]);

        expect(($this->resolve)(WidgetType::Gauge, 'queue_size'))->toMatchArray(['value' => 150.0, 'min' => 0.0, 'max' => 1500.0, 'state' => 'amber'])
            ->and(($this->resolve)(WidgetType::Gauge, 'error_rate'))->toMatchArray(['value' => null, 'max' => 1.0]);
    });

    it('charts requests and server errors side by side', function () {
        $this->createRequestCount($this->project, 9);
        $this->createRequestCount($this->project, 1, 503);

        $series = collect(($this->resolve)(WidgetType::Chart, 'requests_errors')['series'])->keyBy('name');

        expect($series->keys()->all())->toBe(['requests', 'errors'])
            ->and(collect($series['requests']['points'])->sum('v'))->toEqual(10)
            ->and(collect($series['errors']['points'])->sum('v'))->toEqual(1);
    });

    it('splits a chart by project', function () {
        $other = Project::factory()->create(['team_id' => $this->project->team_id, 'name' => 'Zeta']);
        $this->createRequestCount($other, 4);

        $series = ($this->resolve)(WidgetType::Chart, 'requests', ['split_by' => 'project']);

        expect($series['series'][0]['name'])->toBe('Zeta')->and($series['series'])->toHaveCount(2);
    });

    it('narrows a route source to a domain, and finds routes by name', function () {
        $minute = now()->subHour()->startOfMinute();
        $this->createRequestCount($this->project, 30, minute: $minute, domain: 'shop.example.com');
        $this->createRequestCount($this->project, 5, minute: $minute, domain: 'api.example.com');
        $this->createRequestCount($this->project, 2, route: '/cart', minute: $minute, domain: 'api.example.com');
        RequestRun::factory()->for($this->project)->create(['route' => '/orders', 'route_name' => 'orders.index', 'occurred_at' => now()->subHour()]);

        $value = fn (WidgetScope $scope): mixed => ($this->resolve)(WidgetType::Stat, 'requests', scope: $scope)['value'];

        expect($value(new WidgetScope(domain: 'API.example.com')))->toEqual(7)
            ->and($value(new WidgetScope(filter: 'orders.*', domain: 'api.example.com')))->toEqual(5)
            ->and(($this->resolve)(WidgetType::Stat, 'failed_jobs', scope: new WidgetScope(domain: 'api.example.com'))['value'])->toEqual(0);
    });

    it('groups failed jobs by job class, filtered by the scope', function () {
        JobRun::factory()->for($this->project)->failed()->count(3)->create(['name' => 'App\\Jobs\\Bill', 'created_at' => now()->subHour()]);
        JobRun::factory()->for($this->project)->failed()->create(['name' => 'App\\Jobs\\Mail', 'created_at' => now()->subHour()]);

        $rows = ($this->resolve)(WidgetType::List, 'failed_jobs')['rows'];
        $filtered = ($this->resolve)(WidgetType::List, 'failed_jobs', scope: new WidgetScope(filter: '*Mail'))['rows'];

        expect(array_column($rows, 'failures', 'name'))->toBe(['App\\Jobs\\Bill' => 3, 'App\\Jobs\\Mail' => 1])
            ->and(array_column($filtered, 'name'))->toBe(['App\\Jobs\\Mail']);
    });

    it('leaves issue titles and host names out of a shared screen', function () {
        Issue::factory()->for($this->project)->create(['title' => 'Private']);
        ProjectAgent::factory()->for($this->project)->create(['host' => 'db.internal']);
        $resolve = fn (string $source): array => app(ResolveWidgetDataAction::class)->execute($this->project->team, WidgetType::List, $source, new WidgetScope(), aggregatesOnly: true);

        expect($resolve('latest_issues')['rows'][0]['title'])->toBeNull()
            ->and($resolve('agents')['rows'][0]['host'])->toBe('host 1');
    });
});

describe('the sources and scope', function () {
    it('describes a source for the designer, its name translated', function () {
        expect(WidgetSources::find(WidgetType::Gauge, 'apdex')->toArray())->toBe([
            'type' => 'gauge', 'id' => 'apdex', 'label' => 'Apdex', 'topic' => 'traffic', 'unit' => 'score', 'amber' => 0.85, 'red' => 0.7,
            'low_is_bad' => true, 'metric' => 'apdex', 'filter_on' => 'route', 'snapshot' => false,
        ])->and(WidgetSources::has(WidgetType::List, 'queues'))->toBeTrue()
            ->and(WidgetSources::has(WidgetType::Stat, 'queues'))->toBeFalse()
            ->and([WidgetSources::find(WidgetType::Stat, 'queue_workers')->topic(), WidgetSources::find(WidgetType::List, 'hosts')->topic(), WidgetSources::find(WidgetType::Chart, 'db_size')->topic(), WidgetSources::find(WidgetType::List, 'alert_feed')->topic()])->toBe(['jobs', 'infrastructure', 'databases', 'issues'])
            ->and(array_column(array_map(fn (WidgetSource $s): array => $s->toArray(), WidgetSources::for(WidgetType::Health)), 'id'))->toBe(['checks']);
    });

    it('reads a stored scope, ignoring what it cannot use', function () {
        $scope = WidgetScope::fromArray(['projects' => ['a', 3, 'b'], 'environment' => '', 'period' => '7d', 'release' => 'current', 'filter' => 'GET *']);
        $fallback = WidgetScope::fromArray(['period' => 'forever', 'release' => 'latest', 'environment' => ['x']]);

        expect($scope->projects)->toBe(['a', 'b'])
            ->and($scope->environment)->toBeNull()
            ->and($scope->period->value)->toBe('7d')
            ->and($scope->release)->toBe(ReleaseScope::Current)
            ->and($scope->filter)->toBe('GET *')
            ->and($fallback->period->value)->toBe('24h')
            ->and($fallback->release)->toBe(ReleaseScope::All)
            ->and($fallback->environment)->toBeNull();
    });
});

describe('the edges of each type', function () {
    it('compares a stat with yesterday, last week or nothing', function () {
        $this->createRequestCount($this->project, 30);
        $this->createRequestCount($this->project, 10, minute: now()->subDay()->subHour()->startOfMinute());
        $this->createRequestCount($this->project, 60, minute: now()->subDays(7)->subHour()->startOfMinute());

        expect(($this->resolve)(WidgetType::Stat, 'requests', ['compare' => 'yesterday'])['previous'])->toEqual(10)
            ->and(($this->resolve)(WidgetType::Stat, 'requests', ['compare' => 'last_week'])['change'])->toEqual(-0.5)
            ->and(($this->resolve)(WidgetType::Stat, 'requests', ['compare' => 'none', 'sparkline' => false]))->toMatchArray(['previous' => null, 'change' => null, 'sparkline' => null]);
    });

    it('has no value for a source it cannot read, and no release without projects', function () {
        $empty = app(ResolveWidgetDataAction::class)->execute(Team::factory()->create(), WidgetType::Stat, 'current_release', new WidgetScope());

        expect($empty['value'])->toBeNull()->and($empty['state'])->toBeNull()->and($empty)->not->toHaveKey('release');
    });

    it('overlays the previous period on a chart, moved onto its own times', function () {
        $this->createRequestCount($this->project, 5, minute: now()->subDay()->subHours(2)->startOfMinute());

        $series = collect(($this->resolve)(WidgetType::Chart, 'requests', ['overlay_previous' => true])['series'])->keyBy('name');

        expect($series->has('previous'))->toBeTrue()
            ->and(collect($series['previous']['points'])->sum('v'))->toEqual(5)
            ->and(collect($series['previous']['points'])->every(fn (array $p): bool => $p['t'] >= now()->subDay()->subHour()->toIso8601ZuluString()))->toBeTrue();
    });

    it('colors scheduled tasks by how they last ran, failed first', function () {
        ScheduledTask::factory()->for($this->project)->create(['name' => 'fine', 'last_status' => ScheduledRunStatus::Finished]);
        ScheduledTask::factory()->for($this->project)->create(['name' => 'late', 'last_status' => ScheduledRunStatus::Missed]);
        ScheduledTask::factory()->for($this->project)->create(['name' => 'broke', 'last_status' => ScheduledRunStatus::Failed]);
        ScheduledTask::factory()->for($this->project)->create(['name' => 'new', 'last_status' => null]);

        $rows = ($this->resolve)(WidgetType::List, 'scheduled_tasks')['rows'];

        expect(array_column($rows, 'state', 'name'))->toBe(['broke' => 'red', 'late' => 'amber', 'fine' => 'ok', 'new' => null]);
    });

    it('lists hosts with their latest shares, red when offline', function () {
        $agent = ProjectAgent::factory()->for($this->project)->create(['host' => 'web-1']);
        ProjectAgent::factory()->for($this->project)->offline()->create(['host' => 'web-2']);
        HostSample::query()->create(['project_agent_id' => $agent->id, 'cpu_load_1m' => 1.0, 'cpu_cores' => 4, 'memory_used_bytes' => 95, 'memory_total_bytes' => 100,
            'disk_used_bytes' => 10, 'disk_total_bytes' => 100, 'sampled_at' => now()->subMinute()]);

        $rows = collect(($this->resolve)(WidgetType::List, 'hosts')['rows'])->keyBy('host');

        expect($rows['web-1'])->toMatchArray(['cpu_load' => 0.25, 'memory_used' => 0.95, 'disk_used' => 0.1, 'state' => 'red'])
            ->and($rows['web-2'])->toMatchArray(['cpu_load' => null, 'state' => 'red'])
            ->and(($this->resolve)(WidgetType::List, 'agents', ['only_problems' => true])['rows'])->toHaveCount(1);
    });

    it('gives requests per minute over the last minutes, and per bucket on a chart', function () {
        $this->createRequestCount($this->project, 50, minute: now()->subMinutes(2)->startOfMinute());
        // The first of the five whole minutes counts; the minute under way does not yet.
        $this->createRequestCount($this->project, 10, minute: now()->subMinutes(5)->startOfMinute());
        $this->createRequestCount($this->project, 1000, minute: now()->startOfMinute());
        $this->createRequestCount($this->project, 25, minute: now()->subMinutes(7)->startOfMinute());
        $this->createRequestCount($this->project, 600, minute: now()->subMinutes(40)->startOfMinute());

        $stat = ($this->resolve)(WidgetType::Stat, 'requests_per_minute');
        $chart = ($this->resolve)(WidgetType::Chart, 'requests_per_minute', scope: new WidgetScope(period: Period::Hour));
        $points = collect($chart['series'][0]['points']);

        expect($stat['value'])->toEqual(12.0)
            ->and($stat['previous'])->toEqual(5.0)
            ->and($stat['period'])->toBe('now')
            ->and(($this->resolve)(WidgetType::Gauge, 'requests_per_minute')['value'])->toEqual(12.0)
            ->and($points->max('v'))->toEqual(1000 / ($chart['interval'] === '1m' ? 1 : (int) $chart['interval']));
    });

    it('sorts issues newest, most important or last reported first', function () {
        $plain = Issue::factory()->for($this->project)->create(['title' => 'plain', 'surfaced_at' => now()->subMinute(), 'last_seen_at' => now()->subHours(3)]);
        Issue::factory()->for($this->project)->create(['title' => 'regressed', 'surfaced_at' => now()->subHours(2), 'regressed_at' => now()->subHour(), 'last_seen_at' => now()->subHours(2)]);
        Issue::factory()->for($this->project)->create(['title' => 'escalating', 'surfaced_at' => now()->subHours(3), 'escalated_at' => now()->subMinutes(5), 'last_seen_at' => now()->subHour()]);
        Issue::factory()->for($this->project)->create(['title' => 'recent', 'surfaced_at' => now()->subHours(4), 'last_seen_at' => now()]);
        $titles = fn (string $sort): array => array_column(($this->resolve)(WidgetType::List, 'latest_issues', ['sort' => $sort])['rows'], 'title');

        expect($titles('newest'))->toBe(['plain', 'regressed', 'escalating', 'recent'])
            ->and($titles('worst'))->toBe(['escalating', 'regressed', 'plain', 'recent'])
            ->and($titles('last_seen'))->toBe(['recent', 'escalating', 'regressed', 'plain']);
    });

    it('says which period it covers, or now for the latest report', function () {
        expect(($this->resolve)(WidgetType::Stat, 'requests', scope: new WidgetScope(period: Period::Week))['period'])->toBe('7d')
            ->and(($this->resolve)(WidgetType::Health, 'checks')['period'])->toBe('now')
            ->and(($this->resolve)(WidgetType::Gauge, 'host_cpu', scope: new WidgetScope(period: Period::Week))['period'])->toBe('now')
            ->and(($this->resolve)(WidgetType::List, 'queues')['period'])->toBe('now');

        QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'mail', 'size' => 3, 'sampled_at' => now()->subMinutes(2)]);

        expect(($this->resolve)(WidgetType::Stat, 'queue_size')['as_of'])->toBe(now()->subMinutes(2)->toIso8601String())
            ->and(($this->resolve)(WidgetType::Stat, 'requests')['as_of'])->toBeNull()
            ->and(($this->resolve)(WidgetType::Stat, 'open_issues')['as_of'])->toBeNull();
    });

    it('judges a queue like its backlog alert, and says why', function () {
        $sample = fn (string $queue, int $size, int $age) => QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => $queue, 'size' => $size, 'oldest_age_s' => $age, 'sampled_at' => now()->subMinute()]);
        $sample('big', 1200, 5);
        $sample('slow', 3, 11 * 60);
        $sample('half', 600, 0);
        $sample('calm', 10, 0);
        $sample('own', 300, 0);
        $sample('quiet', 5000, 0);
        QueueAlertThreshold::query()->create(['project_id' => $this->project->id, 'connection' => 'redis', 'queue' => 'own', 'size' => 200]);
        QueueAlertThreshold::query()->create(['project_id' => $this->project->id, 'connection' => 'redis', 'queue' => 'quiet', 'muted' => true]);

        $rows = collect(($this->resolve)(WidgetType::List, 'queues')['rows'])->keyBy('queue');

        expect($rows['big'])->toMatchArray(['state' => 'red', 'reason' => 'size'])
            ->and($rows['slow'])->toMatchArray(['state' => 'red', 'reason' => 'age'])
            ->and($rows['half'])->toMatchArray(['state' => 'amber', 'reason' => 'size'])
            ->and($rows['calm'])->toMatchArray(['state' => 'ok', 'reason' => null])
            ->and($rows['own'])->toMatchArray(['state' => 'red'])
            ->and($rows['quiet'])->toMatchArray(['state' => 'ok']);

        $own = collect(($this->resolve)(WidgetType::List, 'queues', ['amber' => 50, 'red' => 5000])['rows'])->keyBy('queue');

        expect($own['big'])->toMatchArray(['state' => 'amber', 'reason' => 'size']);
    });

    it('lists a host once when several agents ran on it, preferring the online one', function () {
        ProjectAgent::factory()->for($this->project)->create(['host' => 'web-1', 'name' => 'old', 'last_seen_at' => now()->subDay()]);
        $live = ProjectAgent::factory()->for($this->project)->create(['host' => 'web-1', 'name' => 'new', 'last_seen_at' => now()]);
        HostSample::query()->create(['project_agent_id' => $live->id, 'cpu_load_1m' => 2.0, 'cpu_cores' => 4, 'memory_used_bytes' => 5, 'memory_total_bytes' => 10,
            'disk_used_bytes' => 1, 'disk_total_bytes' => 10, 'sampled_at' => now()->subMinute()]);

        $rows = ($this->resolve)(WidgetType::List, 'hosts')['rows'];

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['host' => 'web-1', 'online' => true, 'cpu_load' => 0.5]);
    });
});

describe('health', function () {
    beforeEach(function () {
        $this->check = function (string $slug, HealthStatus $status): HealthCheck {
            $check = HealthCheck::factory()->create(['project_id' => $this->project->id, 'slug' => $slug, 'status' => $status, 'status_changed_at' => now()->subMinutes(6)]);
            $check->hosts()->create(['host' => 'web-1', 'status' => $status, 'last_message' => $slug.' says hi', 'last_result_at' => now()]);

            return $check;
        };
    });

    it('counts every check, worst first, and leaves ok ones out when asked', function () {
        ($this->check)('cache:redis', HealthStatus::Ok);
        ($this->check)('disk:s3', HealthStatus::Down);

        $all = ($this->resolve)(WidgetType::Health, 'checks');
        $problems = ($this->resolve)(WidgetType::Health, 'checks', ['only_problems' => true]);

        expect($all['summary'])->toMatchArray(['total' => 2, 'ok' => 1, 'down' => 1])
            ->and(array_column($all['checks'], 'name'))->toBe(['disk:s3', 'cache:redis'])
            ->and($all['checks'][0]['since'])->toBe(now()->subMinutes(6)->toIso8601String())
            ->and($all['check'])->toBeNull()
            ->and(array_column($problems['checks'], 'name'))->toBe(['disk:s3'])
            ->and($problems['summary']['total'])->toBe(2);
    });

    it('shows one picked check in detail, with its uptime and timeline', function () {
        ($this->check)('cache:redis', HealthStatus::Ok);
        $picked = ($this->check)('disk:s3', HealthStatus::Down);

        $data = ($this->resolve)(WidgetType::Health, 'checks', ['checks' => [$picked->uuid]]);

        expect($data['summary']['total'])->toBe(1)
            ->and($data['check'])->toMatchArray(['uuid' => $picked->uuid, 'name' => 'disk:s3', 'state' => 'down', 'last_message' => 'disk:s3 says hi'])
            ->and($data['check'])->toHaveKeys(['uptime', 'strip', 'since']);
    });

    it('names the checks of a shared screen and leaves their messages out', function () {
        ($this->check)('https://admin.internal/up', HealthStatus::Down);
        $picked = ($this->check)('db.internal:5432', HealthStatus::Ok);
        $resolve = fn (array $options = []): array => app(ResolveWidgetDataAction::class)
            ->execute($this->project->team, WidgetType::Health, 'checks', new WidgetScope(), $options, aggregatesOnly: true);

        $all = $resolve();
        $one = $resolve(['checks' => [$picked->uuid]]);

        expect(array_column($all['checks'], 'name'))->toBe(['https://admin.internal/up', 'db.internal:5432'])
            ->and(array_column($all['checks'], 'message'))->toBe([null, null])
            ->and(array_column($all['checks'], 'state'))->toBe(['down', 'ok'])
            ->and($one['check'])->toMatchArray(['name' => 'db.internal:5432', 'last_message' => null, 'state' => 'ok']);
    });

    it('asks for the checks of a project once however many values show them', function () {
        ($this->check)('cache:redis', HealthStatus::Ok);
        $action = app(ResolveWidgetDataAction::class);
        DB::enableQueryLog();

        $action->execute($this->project->team, WidgetType::Health, 'checks', new WidgetScope());
        $first = count(DB::getQueryLog());
        $action->execute($this->project->team, WidgetType::Health, 'checks', new WidgetScope(), ['only_problems' => true]);

        expect(count(DB::getQueryLog()) - $first)->toBeLessThan($first);
    });

    it('reads the checks of every project of the scope in the same few queries', function () {
        $teamWith = function (int $projects): Team {
            $team = Team::factory()->create();

            foreach (range(1, $projects) as $i) {
                $project = Project::factory()->for($team)->create();
                HealthCheck::factory()->create(['project_id' => $project->id, 'slug' => "disk:{$i}"])
                    ->hosts()->create(['host' => 'web-1', 'status' => HealthStatus::Ok, 'last_result_at' => now()]);
            }

            return $team;
        };
        $queries = function (Team $team): int {
            Cache::flush();
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ResolveWidgetDataAction::class)->execute($team, WidgetType::Health, 'checks', new WidgetScope());

            return count(DB::getQueryLog());
        };

        expect($queries($teamWith(4)))->toBe($queries($teamWith(1)));
    });

    it('gauges the lowest uptime of the checks', function () {
        ($this->check)('cache:redis', HealthStatus::Ok)->forceFill(['created_at' => now()->subDays(2)])->save();

        expect(($this->resolve)(WidgetType::Gauge, 'health_uptime'))->toMatchArray(['value' => 1.0, 'max' => 1.0, 'state' => 'ok']);
    });
});

describe('the project overview', function () {
    beforeEach(function () {
        $this->overview = fn (array $options = [], ?Team $team = null): array => app(ResolveWidgetDataAction::class)
            ->execute($team ?? $this->project->team, WidgetType::Overview, 'projects', new WidgetScope(), $options);
        $this->sibling = fn (string $name): Project => Project::factory()->create(['team_id' => $this->project->team_id, 'name' => $name]);
    });

    it('judges each project by its checks, issues and queues, the worst reason first', function () {
        $this->project->update(['name' => 'Alpha']);
        HealthCheck::factory()->create(['project_id' => $this->project->id, 'slug' => 'disk', 'status' => HealthStatus::Down])
            ->hosts()->create(['host' => 'web-1', 'status' => HealthStatus::Down, 'last_result_at' => now()]);
        Issue::factory()->for($this->project)->count(3)->create();
        QueueSample::factory()->forProject($backlogged = ($this->sibling)('Bravo'))->forQueue('mail')->waiting(600)->create(['sampled_at' => now()->subMinute()]);
        Issue::factory()->for($escalating = ($this->sibling)('Charlie'))->escalating()->create();
        Issue::factory()->for($busy = ($this->sibling)('Delta'))->count(3)->create();
        Issue::factory()->for($busy)->create(['first_seen_at' => now()->subDays(3)]);
        ($this->sibling)('Echo');
        Issue::factory()->for(Project::factory()->create())->escalating()->create();

        $data = ($this->overview)();
        $cells = collect($data['projects'])->keyBy('name');

        expect($data['summary'])->toBe(['total' => 5, 'ok' => 1, 'amber' => 2, 'red' => 2])
            ->and($cells->map(fn (array $c): string => $c['state'])->all())->toBe(['Alpha' => 'red', 'Bravo' => 'amber', 'Charlie' => 'red', 'Delta' => 'amber', 'Echo' => 'ok'])
            ->and(array_column($cells['Alpha']['reasons'], 'reason'))->toBe(['checks_down', 'new_issues'])
            ->and($cells['Bravo']['reasons'])->toBe([['reason' => 'backlog', 'state' => 'amber', 'count' => 1, 'queue' => 'mail']])
            ->and($cells['Charlie']['reasons'][0])->toMatchArray(['reason' => 'escalating', 'count' => 1])
            ->and($cells['Delta']['reasons'][0])->toMatchArray(['reason' => 'new_issues', 'count' => 3])
            ->and(collect(($this->overview)(['new_issues' => '5'])['projects'])->firstWhere('name', 'Delta')['state'])->toBe('ok');
    });

    it('turns a queue past the backlog rule red, and holds a stale check amber unless it counts as ok', function () {
        QueueSample::factory()->forProject($this->project)->forQueue('mail')->waiting(1500)->create(['sampled_at' => now()->subMinute()]);
        QueueSample::factory()->forProject($this->project)->forQueue('bulk')->waiting(700)->create(['sampled_at' => now()->subMinute()]);
        QueueAlertThreshold::query()->create(['project_id' => $this->project->id, 'connection' => 'redis', 'queue' => 'bulk', 'muted' => true]);
        $stale = ($this->sibling)('Stale');
        HealthCheck::factory()->reported('cron')->create(['project_id' => $stale->id])
            ->hosts()->create(['host' => 'web-1', 'status' => HealthStatus::Ok, 'last_result_at' => now()->subDays(2)]);

        $cells = collect(($this->overview)()['projects'])->keyBy('project');

        // Past the team's rule of 1,000 jobs; bulk is muted.
        expect($cells[$this->project->slug]['reasons'])->toBe([['reason' => 'backlog', 'state' => 'red', 'count' => 1, 'queue' => 'mail']])
            ->and($cells[$stale->slug]['reasons'])->toBe([['reason' => 'checks_degraded', 'state' => 'amber', 'count' => 1, 'queue' => null]])
            ->and(collect(($this->overview)(['stale_as' => 'ok'])['projects'])->firstWhere('project', $stale->slug)['state'])->toBe('ok');
    });

    it('reads every project of the scope in the same few queries', function () {
        $teamWith = function (int $projects): Team {
            $team = Team::factory()->create();

            foreach (range(1, $projects) as $i) {
                $project = Project::factory()->for($team)->create();
                HealthCheck::factory()->create(['project_id' => $project->id, 'slug' => "disk:{$i}"])
                    ->hosts()->create(['host' => 'web-1', 'status' => HealthStatus::Ok, 'last_result_at' => now()]);
                Issue::factory()->for($project)->escalating()->create();
                QueueSample::factory()->forProject($project)->forQueue('mail')->waiting(800)->create(['sampled_at' => now()->subMinute()]);
                QueueAlertThreshold::query()->create(['project_id' => $project->id, 'connection' => 'redis', 'queue' => 'mail', 'size' => 2000]);
            }

            return $team;
        };
        $queries = function (Team $team): int {
            Cache::flush();
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->overview)([], $team);

            return count(DB::getQueryLog());
        };

        expect($queries($teamWith(4)))->toBe($queries($teamWith(1)));
    });
});

describe('an SLO', function () {
    it('sets the good share of the period against its budget, and how fast it burns now', function () {
        $this->createRequestCount($this->project, 1990, minute: now()->subHours(5)->startOfMinute());
        $this->createRequestCount($this->project, 90, minute: now()->subMinutes(10)->startOfMinute());
        $this->createRequestCount($this->project, 10, 500, minute: now()->subMinutes(10)->startOfMinute());
        $slo = fn (array $options): array => ($this->resolve)(WidgetType::Slo, 'availability', $options, new WidgetScope(period: Period::Day));

        // 10 of 2090 failed: 0.48% of a 1% budget used, and the last hour burns it ten times over.
        expect($slo(['slo_target' => '99']))->toMatchArray(['target' => 0.99, 'budget_remaining' => 0.5215, 'burn_rate' => 10.0, 'burn_seconds' => 3600, 'state' => 'amber'])
            ->and($slo(['slo_target' => '99'])['value'])->toEqualWithDelta(0.995215, 0.000001)
            ->and($slo(['slo_target' => '99_9'])['state'])->toBe('red')
            ->and($slo(['slo_target' => 'custom', 'slo_custom_target' => 50]))->toMatchArray(['target' => 0.5, 'burn_rate' => 0.2, 'state' => 'ok']);
    });

    it('counts the sampled requests under the threshold for latency', function () {
        RequestRun::factory()->for($this->project)->count(3)->create(['duration_us' => 100_000, 'occurred_at' => now()->subMinutes(10)]);
        RequestRun::factory()->for($this->project)->create(['duration_us' => 900_000, 'occurred_at' => now()->subMinutes(10)]);

        expect(($this->resolve)(WidgetType::Slo, 'latency', ['slo_target' => '99']))->toMatchArray(['value' => 0.75, 'threshold_ms' => 500, 'state' => 'red'])
            ->and(($this->resolve)(WidgetType::Slo, 'latency', ['threshold_ms' => 1000])['value'])->toEqual(1.0);
    });
});

describe('database checks', function () {
    beforeEach(function () {
        $this->pgsql = HealthCheck::factory()->for($this->project)->create(['slug' => 'database:pgsql']);
        $this->mysql = HealthCheck::factory()->for($this->project)->create(['slug' => 'database:mysql']);
        // Two hosts report the same database: it counts once, at its latest figures.
        $this->createDatabaseResult($this->pgsql, ['size_bytes' => 1000, 'connections' => 10, 'max_connections' => 100], now()->subHours(2)->toImmutable());
        $this->createDatabaseResult($this->pgsql, ['size_bytes' => 1200, 'connections' => 20, 'max_connections' => 100], now()->subMinutes(5)->toImmutable(), 'web-2');
        $this->createDatabaseResult($this->mysql, ['size_bytes' => 300, 'connections' => 45, 'max_connections' => 50], now()->subMinutes(3)->toImmutable());
    });

    it('adds up the latest size of every database, or shows one check', function () {
        $data = ($this->resolve)(WidgetType::Stat, 'db_size');

        expect($data)->toMatchArray(['value' => 1500.0, 'unit' => 'bytes', 'period' => 'now', 'as_of' => now()->subMinutes(3)->toIso8601String()])
            ->and($data['sparkline'])->not->toBeEmpty()
            ->and(($this->resolve)(WidgetType::Stat, 'db_size', scope: new WidgetScope(filter: 'database:pgsql'))['value'])->toBe(1200.0)
            ->and(($this->resolve)(WidgetType::Stat, 'db_size', scope: new WidgetScope(filter: '*mysql'))['value'])->toBe(300.0)
            ->and(($this->resolve)(WidgetType::Stat, 'db_connections')['value'])->toBe(65.0);
    });

    it('gauges the database with the largest share of its connections in use', function () {
        expect(($this->resolve)(WidgetType::Gauge, 'db_connections'))->toMatchArray(['value' => 0.9, 'max' => 1.0, 'state' => 'red'])
            ->and(($this->resolve)(WidgetType::Gauge, 'db_connections', scope: new WidgetScope(filter: 'database:pgsql'))['value'])->toBe(0.2);
    });

    it('charts the size per bucket, one series per check when split', function () {
        $series = fn (array $options = []): array => collect(($this->resolve)(WidgetType::Chart, 'db_size', $options)['series'])
            ->mapWithKeys(fn (array $s): array => [$s['name'] => array_column($s['points'], 'v')])->all();

        expect($series())->toBe(['db_size' => [1000.0, 1500.0]])
            ->and($series(['split_by' => 'check']))->toBe(['database:pgsql' => [1000.0, 1200.0], 'database:mysql' => [300.0]]);
    });

    it('names the checks a chart on a shared screen is split by', function () {
        $data = app(ResolveWidgetDataAction::class)
            ->execute($this->project->team, WidgetType::Chart, 'db_size', new WidgetScope(), ['split_by' => 'check'], aggregatesOnly: true);

        expect(array_column($data['series'], 'name'))->toBe(['database:pgsql', 'database:mysql']);
    });

    it('gives a single picked check the metrics of its latest result', function () {
        $data = ($this->resolve)(WidgetType::Health, 'checks', ['checks' => [$this->mysql->uuid]]);

        expect($data['check']['metrics'])->toBe(['size_bytes' => 300, 'connections' => 45, 'max_connections' => 50]);
    });
});

describe('external JSON', function () {
    beforeEach(function () {
        $this->source = DataSource::factory()->for($this->project)->create([
            'name' => 'Shop',
            'last_ok_at' => now(),
            'last_body' => '{"orders": 30, "services": [{"name": "api", "status": "ok", "ms": 120}, {"name": "pay", "status": "down", "ms": 900}, {"name": "web", "status": "ok", "ms": 80}]}',
        ]);
        $fetch = fn (int $orders, CarbonInterface $at) => DB::table('data_source_fetches')->insert(['project_id' => $this->project->id, 'data_source_id' => $this->source->id, 'body' => json_encode(['orders' => $orders]), 'fetched_at' => $at]);
        $fetch(10, now()->subDay()->subMinutes(5));
        $fetch(15, now()->subHours(3)->subSeconds(20));
        $fetch(20, now()->subHours(3));
        $this->external = fn (array $options = []): array => ['data_source' => $this->source->uuid, 'json_path' => '$.orders', ...$options];
    });

    it('shows a stat from the latest response, against a period back, with a sparkline from the history', function () {
        $data = ($this->resolve)(WidgetType::Stat, 'external_json', ($this->external)(['amber' => 25, 'red' => 50]));

        expect($data)->toMatchArray(['value' => 30.0, 'previous' => 10.0, 'change' => 2.0, 'state' => 'amber', 'period' => 'now', 'as_of' => now()->toIso8601String()])
            ->and(array_values(array_filter(array_column($data['sparkline'], 'v'), fn ($v) => $v !== null)))->toBe([20.0]);
    });

    it('shows a gauge from a filter expression', function () {
        $data = ($this->resolve)(WidgetType::Gauge, 'external_json', ($this->external)(['json_path' => "$.services[?(@.status=='down')].ms", 'max' => 1000, 'red' => 500]));

        expect($data)->toMatchArray(['value' => 900.0, 'min' => 0.0, 'max' => 1000.0, 'state' => 'red']);
    });

    it('charts the history, the last fetch of each bucket', function () {
        $data = ($this->resolve)(WidgetType::Chart, 'external_json', ($this->external)(), new WidgetScope(period: Period::Day));
        $points = collect($data['series'][0]['points'])->whereNotNull('v');

        expect($data['series'][0]['name'])->toBe('Shop')
            ->and($data['interval'])->toBe('1h')
            ->and($points->pluck('v')->all())->toBe([20.0])
            ->and($points->first()['t'])->toBe(gmdate('Y-m-d\TH:00:00\Z', now()->subHours(3)->getTimestamp()));
    });

    it('lists the items a filter expression finds, with their labels, values and states', function () {
        $data = ($this->resolve)(WidgetType::List, 'external_json', ($this->external)(['json_path' => "$.services[?(@.ms > 100)]", 'label_path' => 'name', 'value_path' => '$.ms', 'red' => 500]));

        expect($data['columns'])->toBe(['label', 'value'])
            ->and($data['rows'])->toBe([
                ['project' => $this->project->slug, 'label' => 'api', 'value' => 120.0, 'state' => 'ok'],
                ['project' => $this->project->slug, 'label' => 'pay', 'value' => 900.0, 'state' => 'red'],
            ]);
    });

    it('numbers the rows on a share link without names', function () {
        $data = app(ResolveWidgetDataAction::class)->execute($this->project->team, WidgetType::List, 'external_json', new WidgetScope(), ($this->external)(['json_path' => '$.services', 'label_path' => 'name']), aggregatesOnly: true);

        expect(array_column($data['rows'], 'label'))->toBe(['#1', '#2', '#3']);
    });

    it('keeps only the numbers of the rows and the chart on a share link without names', function () {
        $resolve = fn (WidgetType $type, array $options): array => app(ResolveWidgetDataAction::class)->execute($this->project->team, $type, 'external_json', new WidgetScope(period: Period::Day), ($this->external)($options), aggregatesOnly: true);

        expect(array_column($resolve(WidgetType::List, ['json_path' => '$.services', 'label_path' => 'name', 'value_path' => 'status'])['rows'], 'value'))->toBe([null, null, null])
            ->and(array_column($resolve(WidgetType::List, ['json_path' => '$.services', 'label_path' => 'name', 'value_path' => 'ms'])['rows'], 'value'))->toBe([120.0, 900.0, 80.0])
            ->and($resolve(WidgetType::Chart, [])['series'][0]['name'])->toBe('source 1');
    });

    it('compares a stat with nothing fetched long before the period back', function () {
        DB::table('data_source_fetches')->delete();
        DB::table('data_source_fetches')->insert(['project_id' => $this->project->id, 'data_source_id' => $this->source->id, 'body' => '{"orders": 5}', 'fetched_at' => now()->subDays(3)]);

        expect(($this->resolve)(WidgetType::Stat, 'external_json', ($this->external)(['compare' => 'yesterday']))['previous'])->toBeNull()
            ->and(($this->resolve)(WidgetType::Stat, 'external_json', ($this->external)(['compare' => 'last_week']))['previous'])->toBeNull();
    });

    it('reads no source of a project outside the team', function () {
        $other = DataSource::factory()->create(['last_ok_at' => now(), 'last_body' => '{"orders": 99}']);

        $data = ($this->resolve)(WidgetType::Stat, 'external_json', ['data_source' => $other->uuid, 'json_path' => '$.orders']);

        expect($data['value'])->toBeNull()
            ->and($data['as_of'])->toBeNull();
    });
});

describe('external Prometheus', function () {
    beforeEach(function () {
        $this->travelTo(now()->setTime(12, 30, 30));
        $text = fn (int $ok, int $failed): string => "# TYPE http_requests_total counter\nhttp_requests_total{status=\"200\"} {$ok}\nhttp_requests_total{status=\"500\"} {$failed}\n# TYPE queue_depth gauge\nqueue_depth{queue=\"a\"} 5\nqueue_depth{queue=\"b\"} 2\n";
        $this->source = DataSource::factory()->for($this->project)->create(['name' => 'Metrics', 'format' => DataSourceFormat::Prometheus, 'last_ok_at' => now(), 'last_body' => $text(280, 8)]);
        $fetch = fn (int $ok, int $failed, CarbonInterface $at) => DB::table('data_source_fetches')->insert(['project_id' => $this->project->id, 'data_source_id' => $this->source->id, 'body' => json_encode(Prometheus::parse($text($ok, $failed))), 'fetched_at' => $at]);
        $fetch(100, 10, now()->subHours(2));
        // 500 drops from 10 to 4: a reset.
        $fetch(160, 4, now()->subHour());
        $fetch(220, 6, now()->subMinutes(2));
        $this->external = fn (string $selector, array $options = []): array => ['data_source' => $this->source->uuid, 'json_path' => $selector, ...$options];
    });

    it('shows a counter on a stat as its increase per minute since the fetch before the latest', function () {
        $data = ($this->resolve)(WidgetType::Stat, 'external_json', ($this->external)('sum(http_requests_total)', ['compare' => 'none', 'sparkline' => false]));

        // (280 - 220 + 8 - 6) over two minutes.
        expect($data['value'])->toBe(31.0);
    });

    it('compares a counter with its rate a period back, from the two fetches by then', function () {
        DB::table('data_source_fetches')->insert(['project_id' => $this->project->id, 'data_source_id' => $this->source->id, 'body' => json_encode(Prometheus::parse("# TYPE http_requests_total counter\nhttp_requests_total{status=\"200\"} 150\nhttp_requests_total{status=\"500\"} 3\n")), 'fetched_at' => now()->subHour()->subMinute()]);

        $data = ($this->resolve)(WidgetType::Stat, 'external_json', ($this->external)('sum(http_requests_total)', ['sparkline' => false]), new WidgetScope(period: Period::Hour));

        // 150 to 160 and 3 to 4 in the minute before an hour ago.
        expect($data)->toMatchArray(['value' => 31.0, 'previous' => 11.0]);
    });

    it('shows a gauge as it is, and only one group of sum by', function () {
        expect(($this->resolve)(WidgetType::Gauge, 'external_json', ($this->external)('queue_depth{queue="a"}'))['value'])->toBe(5.0)
            ->and(($this->resolve)(WidgetType::Gauge, 'external_json', ($this->external)('queue_depth'))['value'])->toBe(7.0)
            ->and(($this->resolve)(WidgetType::Gauge, 'external_json', ($this->external)('sum by (queue) (queue_depth{queue=~"a|b"})'))['value'])->toBeNull()
            ->and(($this->resolve)(WidgetType::Gauge, 'external_json', ($this->external)('$.orders'))['value'])->toBeNull();
    });

    it('charts a counter as the increase per minute between consecutive points, a reset as the new value', function () {
        $data = ($this->resolve)(WidgetType::Chart, 'external_json', ($this->external)('sum(http_requests_total)'), new WidgetScope(period: Period::Day));

        expect(array_map(fn (float $v): float => round($v, 4), array_values(array_filter(array_column($data['series'][0]['points'], 'v'), fn ($v) => $v !== null))))
            ->toBe([round(64 / 60, 4), round(62 / 58, 4)]);
    });

    it('lists a row per group of sum by, or per series, a counter per minute', function () {
        $rows = fn (string $selector): array => array_map(fn (array $r): array => [$r['label'], $r['value']], ($this->resolve)(WidgetType::List, 'external_json', ($this->external)($selector))['rows']);

        expect($rows('sum by (status) (http_requests_total)'))->toBe([['200', 30.0], ['500', 1.0]])
            ->and($rows('queue_depth'))->toBe([['queue=a', 5.0], ['queue=b', 2.0]]);
    });

    it('has no counter rate before a second fetch', function () {
        DB::table('data_source_fetches')->delete();

        expect(($this->resolve)(WidgetType::Stat, 'external_json', ($this->external)('http_requests_total', ['compare' => 'none']))['value'])->toBeNull();
    });
});

describe('a calendar', function () {
    beforeEach(function () {
        // A Wednesday, 13:00 in Copenhagen: the lunch talk is on, the early sync over.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:30', 'UTC'));
        $this->calendar = DataSource::factory()->for($this->project)->ical((string) file_get_contents(base_path('tests/Fixtures/DataSources/calendar.ics')))->create();
        $this->rows = fn (array $options = [], bool $aggregatesOnly = false): array => app(ResolveWidgetDataAction::class)->execute(
            $this->project->team,
            WidgetType::List,
            'calendar',
            new WidgetScope(),
            ['data_source' => $this->calendar->uuid, 'timezone' => 'Europe/Copenhagen', ...$options],
            $aggregatesOnly,
        )['rows'];
        $this->brief = fn (array $rows): array => array_map(fn (array $r): string => trim("{$r['day']} ".($r['all_day'] ? 'all day' : "{$r['start']}-{$r['end']}")." {$r['title']} {$r['state']}"), $rows);
    });

    it('lists the days in the screen\'s time zone, all-day events first, marking the one on now', function () {
        expect(($this->brief)(($this->rows)(['days_after' => '1'])))->toBe([
            '2026-11-04 12:30-14:00 Lunch talk amber',
            '2026-11-05 all day Release day',
            '2026-11-05 09:00-09:30 Deploy',
        ])->and(($this->rows)(['days_after' => '0'])[0])->toMatchArray(['location' => 'Canteen', 'project' => $this->project->slug]);
    });

    it('keeps what has ended when asked, from the days back', function () {
        expect(($this->brief)(($this->rows)(['days_before' => '7', 'days_after' => '0', 'upcoming_only' => false])))->toBe([
            '2026-11-02 09:30-09:45 Standup',
            '2026-11-04 08:00-08:30 Early sync',
            '2026-11-04 12:30-14:00 Lunch talk amber',
        ]);
    });

    it('expands a weekly event over the month, its excluded date skipped and its moved one moved', function () {
        expect(array_values(array_filter(($this->brief)(($this->rows)(['days_after' => '30'])), fn (string $r): bool => str_contains($r, 'Standup'))))->toBe([
            '2026-11-17 14:00-14:15 Standup (moved)',
            '2026-11-23 09:30-09:45 Standup',
            '2026-11-30 09:30-09:45 Standup',
        ]);
    });

    it('shows a share link without names the times alone', function () {
        expect(($this->rows)(['days_after' => '1'], aggregatesOnly: true))->each->toMatchArray(['title' => null, 'location' => null]);
    });

    it('reads only an iCalendar source of the team', function () {
        $json = DataSource::factory()->for($this->project)->create(['last_ok_at' => now(), 'last_body' => '{"orders": 1}']);
        $other = DataSource::factory()->ical((string) $this->calendar->last_body)->create();

        expect(($this->rows)(['data_source' => $json->uuid]))->toBe([])
            ->and(($this->rows)(['data_source' => $other->uuid]))->toBe([]);
    });
});
