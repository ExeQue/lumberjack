<?php

declare(strict_types=1);

use App\Domains\Alerts\Models\QueueAlertThreshold;
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
            'type' => 'gauge', 'id' => 'apdex', 'label' => 'Apdex', 'unit' => 'score', 'amber' => 0.85, 'red' => 0.7,
            'low_is_bad' => true, 'metric' => 'apdex', 'filter_on' => 'route', 'snapshot' => false,
        ])->and(WidgetSources::has(WidgetType::List, 'queues'))->toBeTrue()
            ->and(WidgetSources::has(WidgetType::Stat, 'queues'))->toBeFalse()
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

    it('numbers the checks of a shared screen and leaves their messages out', function () {
        ($this->check)('https://admin.internal/up', HealthStatus::Down);
        $picked = ($this->check)('db.internal:5432', HealthStatus::Ok);
        $resolve = fn (array $options = []): array => app(ResolveWidgetDataAction::class)
            ->execute($this->project->team, WidgetType::Health, 'checks', new WidgetScope(), $options, aggregatesOnly: true);

        $all = $resolve();
        $one = $resolve(['checks' => [$picked->uuid]]);

        expect(array_column($all['checks'], 'name'))->toBe(['check 1', 'check 2'])
            ->and(array_column($all['checks'], 'message'))->toBe([null, null])
            ->and(array_column($all['checks'], 'state'))->toBe(['down', 'ok'])
            ->and($one['check'])->toMatchArray(['name' => 'check 1', 'last_message' => null, 'state' => 'ok']);
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

    it('gauges the lowest uptime of the checks', function () {
        ($this->check)('cache:redis', HealthStatus::Ok)->forceFill(['created_at' => now()->subDays(2)])->save();

        expect(($this->resolve)(WidgetType::Gauge, 'health_uptime'))->toMatchArray(['value' => 1.0, 'max' => 1.0, 'state' => 'ok']);
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

    it('numbers the checks a chart on a shared screen is split by', function () {
        $data = app(ResolveWidgetDataAction::class)
            ->execute($this->project->team, WidgetType::Chart, 'db_size', new WidgetScope(), ['split_by' => 'check'], aggregatesOnly: true);

        expect(array_column($data['series'], 'name'))->toBe(['check 1', 'check 2']);
    });

    it('gives a single picked check the metrics of its latest result', function () {
        $data = ($this->resolve)(WidgetType::Health, 'checks', ['checks' => [$this->mysql->uuid]]);

        expect($data['check']['metrics'])->toBe(['size_bytes' => 300, 'connections' => 45, 'max_connections' => 50]);
    });
});
