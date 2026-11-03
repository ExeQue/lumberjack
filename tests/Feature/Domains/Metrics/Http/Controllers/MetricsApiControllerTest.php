<?php

declare(strict_types=1);

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Metrics\Http\Controllers\MetricsApiController;
use App\Domains\Metrics\Http\Middleware\AuthenticateMetricsCredential;
use App\Domains\Metrics\Providers\MetricsServiceProvider;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Health\CreatesDatabaseHealthResults;
use Tests\Support\Metrics\CreatesMetricsCredentials;
use Tests\Support\Performance\CreatesRequestCounts;

covers(MetricsApiController::class, AuthenticateMetricsCredential::class);

uses(CreatesMetricsCredentials::class, CreatesRequestCounts::class, CreatesDatabaseHealthResults::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0, 10));
});

describe('a team token', function () {
    it('lists the projects it covers', function () {
        $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
        Project::factory()->create(['team_id' => $project->team_id]);

        $this->withToken($this->createMetricsToken($project))->getJson('/api/v1/projects')
            ->assertOk()
            ->assertExactJson(['projects' => [['uuid' => $project->uuid, 'slug' => 'shop', 'name' => 'Shop']]]);
    });

    it('reads a project by slug or uuid', function () {
        $project = Project::factory()->create();
        $token = $this->createMetricsToken($project);

        $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/summary")->assertOk()->assertJsonPath('project', $project->slug);
        $this->withToken($token)->getJson("/api/v1/projects/{$project->uuid}/summary")->assertOk();
    });

    it('rejects a missing, unknown or expired token', function () {
        $project = Project::factory()->create();
        $plain = $this->createMetricsToken($project);
        McpToken::query()->update(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->withToken('lj_mcp_nope')->getJson('/api/v1/projects')->assertUnauthorized();
        $this->withToken($plain)->getJson('/api/v1/projects')->assertUnauthorized();
    });

    it('does not serve a project it does not cover, of its team or another', function () {
        $project = Project::factory()->create();
        $sibling = Project::factory()->create(['team_id' => $project->team_id]);
        $foreign = Project::factory()->create();
        $token = $this->createMetricsToken($project);

        $this->withToken($token)->getJson("/api/v1/projects/{$sibling->slug}/summary")->assertNotFound();
        $this->withToken($token)->getJson("/api/v1/projects/{$foreign->uuid}/routes")->assertNotFound();
    });

    it('stops at the rate limit', function () {
        $project = Project::factory()->create();
        $token = $this->createMetricsToken($project);

        for ($i = 0; $i < MetricsServiceProvider::REQUESTS_PER_MINUTE; $i++) {
            $this->withToken($token)->getJson('/api/v1/projects')->assertOk();
        }

        $this->withToken($token)->getJson('/api/v1/projects')->assertTooManyRequests();
    });

    it('turns away an address that keeps sending unknown tokens', function () {
        for ($i = 0; $i < AuthenticateMetricsCredential::FAILURES_PER_MINUTE; $i++) {
            $this->withToken('lj_mcp_guess'.$i)->getJson('/api/v1/projects')->assertUnauthorized();
        }

        $this->withToken('lj_mcp_guess')->getJson('/api/v1/projects')->assertTooManyRequests();
    });
});

describe('public stats', function () {
    it('serves the project under its key without a token', function () {
        $project = Project::factory()->create();
        $key = $this->enablePublicStats($project);

        $this->getJson("/api/v1/public/{$key}/projects")->assertOk()->assertJsonPath('projects.0.uuid', $project->uuid);
        $this->getJson("/api/v1/public/{$key}/projects/{$project->slug}/summary")->assertOk();
    });

    it('is off by default and stops with the key', function () {
        $project = Project::factory()->create();
        $key = $this->enablePublicStats($project);
        $project->update(['stats_key' => null]);

        expect(Project::factory()->create()->stats_key)->toBeNull();
        $this->getJson("/api/v1/public/{$key}/projects")->assertNotFound();
    });

    it('serves no other project under the key', function () {
        $project = Project::factory()->create();
        $sibling = Project::factory()->create(['team_id' => $project->team_id]);
        $key = $this->enablePublicStats($project);

        $this->getJson("/api/v1/public/{$key}/projects/{$sibling->slug}/summary")->assertNotFound();
    });

    it('leaves out issue titles unless the project shares them', function (bool $titles, ?string $expected) {
        $project = Project::factory()->create();
        Issue::factory()->for($project)->create(['title' => 'Secret customer 42 failed']);
        $key = $this->enablePublicStats($project, $titles);

        $this->getJson("/api/v1/public/{$key}/projects/{$project->slug}/issues")->assertOk()->assertJsonPath('rows.0.1', $expected);
    })->with([
        'titles kept private' => [false, null],
        'titles shared' => [true, 'Secret customer 42 failed'],
    ]);

    it('leaves out host names and query strings', function () {
        $project = Project::factory()->create();
        ProjectAgent::factory()->for($project)->create(['host' => 'db-primary.internal']);
        RequestRun::factory()->for($project)->create(['route' => null, 'url' => '/search?q=private', 'occurred_at' => now()->subMinutes(5)]);
        $key = $this->enablePublicStats($project);

        $this->getJson("/api/v1/public/{$key}/projects/{$project->slug}/hosts")->assertOk()->assertJsonPath('rows.0.0', 'host 1');
        $this->getJson("/api/v1/public/{$key}/projects/{$project->slug}/routes")->assertOk()->assertJsonPath('rows.0.1', '/search');
        $this->getJson("/api/v1/public/{$key}/projects/{$project->slug}/series/p95?group_by=host")->assertUnprocessable();
    });
});

describe('the series', function () {
    it('serves database size and connections per check from reported health results', function () {
        $project = Project::factory()->create();
        $pgsql = HealthCheck::factory()->for($project)->create(['slug' => 'database:pgsql']);
        $mysql = HealthCheck::factory()->for($project)->create(['slug' => 'database:mysql']);
        $this->createDatabaseResult($pgsql, ['size_bytes' => 2048, 'connections' => 4, 'max_connections' => 100], now()->subMinutes(20)->toImmutable());
        $this->createDatabaseResult($mysql, ['size_bytes' => 1024, 'connections' => 8, 'max_connections' => 10], now()->subMinutes(20)->toImmutable());
        $range = '?from='.now()->subHour()->getTimestampMs().'&to='.now()->getTimestampMs().'&interval=1h';
        $token = $this->createMetricsToken($project);

        $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/series/db_size{$range}&group_by=check")
            ->assertOk()
            ->assertJsonPath('unit', 'bytes')
            ->assertJsonPath('points', [
                ['t' => now()->setTime(11, 0)->format('Y-m-d\TH:i:s\Z'), 'series' => 'database:pgsql', 'value' => 2048],
                ['t' => now()->setTime(11, 0)->format('Y-m-d\TH:i:s\Z'), 'series' => 'database:mysql', 'value' => 1024],
            ]);
        $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/series/db_connections{$range}&check=database:mysql")
            ->assertOk()
            ->assertJsonPath('points.0.value', 8);
        $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/series/db_connections_used{$range}")
            ->assertOk()
            ->assertJsonPath('points.0.value', 0.8);
    });

    it('counts requests from the SDK counts and buckets them', function () {
        $project = Project::factory()->create();
        $this->createRequestCount($project, 95, minute: now()->subMinutes(30)->startOfMinute());
        $this->createRequestCount($project, 5, 500, minute: now()->subMinutes(30)->startOfMinute());
        RequestRun::factory()->for($project)->count(10)->create(['route' => '/orders', 'occurred_at' => now()->subMinutes(30), 'sample_rate' => 0.1]);

        $response = $this->withToken($this->createMetricsToken($project))
            ->getJson("/api/v1/projects/{$project->slug}/series/requests?from=".now()->subHour()->getTimestampMs().'&to='.now()->getTimestampMs().'&interval=5m')
            ->assertOk()
            ->assertJsonPath('metric', 'requests')
            ->assertJsonPath('unit', 'count')
            ->assertJsonPath('interval', '5m');

        $points = collect($response->json('points'));

        expect($points)->toHaveCount(12)
            ->and($points->sum('value'))->toEqual(100)
            ->and($points->firstWhere('value', 100)['t'])->toBe(now()->setTime(11, 30)->format('Y-m-d\TH:i:s\Z'));
    });

    it('takes percentiles only from the runs the SDK picked', function () {
        $project = Project::factory()->create();
        RequestRun::factory()->for($project)->count(3)->create(['duration_us' => 100_000, 'occurred_at' => now()->subMinutes(10)]);
        RequestRun::factory()->for($project)->create(['duration_us' => 9_000_000, 'sampled' => false, 'occurred_at' => now()->subMinutes(10)]);

        $points = $this->withToken($this->createMetricsToken($project))
            ->getJson("/api/v1/projects/{$project->slug}/series/p95?from=".now()->subHour()->toIso8601String().'&interval=1h')
            ->assertOk()->json('points');

        expect(array_column($points, 'value'))->toEqual([100]);
    });

    it('splits by a dimension, one series each', function () {
        $project = Project::factory()->create();
        $this->createRequestCount($project, 7, route: '/a', minute: now()->subMinutes(5)->startOfMinute());
        $this->createRequestCount($project, 3, route: '/b', minute: now()->subMinutes(5)->startOfMinute());

        $points = $this->withToken($this->createMetricsToken($project))
            ->getJson("/api/v1/projects/{$project->slug}/series/requests?group_by=route&interval=1h")
            ->assertOk()->json('points');

        expect(collect($points)->groupBy('series')->map->sum('value')->all())->toEqual(['/a' => 7, '/b' => 3]);
    });

    it('refuses an unknown metric or a split the metric does not have', function () {
        $project = Project::factory()->create();
        $token = $this->createMetricsToken($project);

        $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/series/nope")->assertUnprocessable();
        $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/series/cpu_load?group_by=route")->assertUnprocessable()->assertJsonValidationErrors('group_by');
    });

    it('reads the interval in any unit, in whole minutes', function (int $days, string $interval, string $expected) {
        $project = Project::factory()->create();

        $this->withToken($this->createMetricsToken($project))
            ->getJson("/api/v1/projects/{$project->slug}/series/requests?from=".now()->subDays($days)->getTimestampMs()."&interval={$interval}")
            ->assertOk()
            ->assertJsonPath('interval', $expected);
    })->with([
        'milliseconds' => [1, '90000ms', '2m'],
        'seconds, at least a minute' => [1, '30s', '1m'],
        'hours' => [1, '2h', '2h'],
        'days' => [30, '7d', '7d'],
        'too fine for the range' => [30, '1m', '22m'],
    ]);

    it('keeps the window sane', function () {
        $project = Project::factory()->create();
        $token = $this->createMetricsToken($project);
        $series = fn (string $query) => $this->withToken($token)->getJson("/api/v1/projects/{$project->slug}/series/requests?{$query}")->assertOk();

        $flipped = $series('from='.now()->getTimestampMs().'&to='.now()->subHour()->getTimestampMs());
        expect(CarbonImmutable::parse($flipped->json('to'))->diffInSeconds(CarbonImmutable::parse($flipped->json('from')), true))->toEqual(60);

        $old = $series('from='.now()->subYear()->getTimestampMs().'&interval=1d');
        expect(CarbonImmutable::parse($old->json('from'))->diffInDays(CarbonImmutable::parse($old->json('to')), true))->toEqual(90.0);

        // A + in an unencoded query string arrives as a space.
        $spaced = $series('from='.now()->subHour()->format('Y-m-d\TH:i:s').' 00:00');
        expect(CarbonImmutable::parse($spaced->json('from'))->diffInMinutes(now()->subHour(), true))->toBeLessThan(1.0);
    });

    it('refuses a time it cannot read', function () {
        $project = Project::factory()->create();

        $this->withToken($this->createMetricsToken($project))
            ->getJson("/api/v1/projects/{$project->slug}/series/requests?from=not-a-time")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    });

    it('answers identical questions from the cache', function () {
        $project = Project::factory()->create();
        $token = $this->createMetricsToken($project);
        $url = "/api/v1/projects/{$project->slug}/series/requests?interval=1h";

        $this->withToken($token)->getJson($url)->assertOk();
        $this->createRequestCount($project, 50, minute: now()->subMinutes(2)->startOfMinute());

        expect(collect($this->withToken($token)->getJson($url)->json('points'))->sum('value'))->toEqual(0);

        $this->travel(31)->seconds();

        expect(collect($this->withToken($token)->getJson($url)->json('points'))->sum('value'))->toEqual(50);
    });
});

describe('the summary and tables', function () {
    it('sums up the period like the overview page', function () {
        $project = Project::factory()->create();
        $this->createRequestCount($project, 90);
        $this->createRequestCount($project, 10, 500);
        Issue::factory()->for($project)->count(2)->create();

        $this->withToken($this->createMetricsToken($project))->getJson("/api/v1/projects/{$project->slug}/summary?period=24h")
            ->assertOk()
            ->assertJsonPath('requests', 100)
            ->assertJsonPath('error_rate', 0.1)
            ->assertJsonPath('open_issues', 2)
            ->assertJsonPath('period', '24h');
    });

    it('serves each table as typed columns and rows', function (string $table, string $firstColumn) {
        $project = Project::factory()->create();
        $this->createRequestCount($project, 4);

        $this->withToken($this->createMetricsToken($project))->getJson("/api/v1/projects/{$project->slug}/{$table}")
            ->assertOk()
            ->assertJsonPath('columns.0.name', $firstColumn)
            ->assertJsonStructure(['columns' => [['name', 'type', 'unit']], 'rows']);
    })->with([
        'routes' => ['routes', 'method'],
        'issues' => ['issues', 'reference'],
        'jobs' => ['jobs', 'name'],
        'queues' => ['queues', 'connection'],
        'hosts' => ['hosts', 'host'],
        'releases' => ['releases', 'release'],
    ]);
});

describe('the Prometheus page', function () {
    it('exposes the gauges of every project the token covers', function () {
        $project = Project::factory()->create(['slug' => 'shop']);
        $this->createRequestCount($project, 42, minute: now()->subMinutes(10)->startOfMinute());
        QueueSample::query()->create(['project_id' => $project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'mail', 'size' => 7, 'oldest_age_s' => 30, 'sampled_at' => now()->subMinute()]);
        $agent = ProjectAgent::factory()->for($project)->create();
        HostSample::query()->create(['project_agent_id' => $agent->id, 'cpu_load_1m' => 1, 'cpu_cores' => 2, 'sampled_at' => now()]);
        $this->createDatabaseResult(HealthCheck::factory()->for($project)->create(), ['size_bytes' => 4096, 'connections' => 3, 'max_connections' => 100]);

        $response = $this->withToken($this->createMetricsToken($project))->get('/api/v1/metrics')->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('text/plain; version=0.0.4')
            ->and($response->getContent())
            ->toContain('# TYPE lumberjack_requests gauge')
            ->toContain('lumberjack_requests{project="shop"} 42')
            ->toContain('lumberjack_queue_size{project="shop",connection="redis",queue="mail"} 7')
            ->toContain('lumberjack_agents_online{project="shop"} 1')
            ->toContain('lumberjack_database_size_bytes{project="shop",environment="production",check="database:pgsql"} 4096')
            ->toContain('lumberjack_database_max_connections{project="shop",environment="production",check="database:pgsql"} 100')
            ->toContain('lumberjack_health_checks{project="shop",state="down"} 0');
    });

    it('is served under a public stats key too', function () {
        $project = Project::factory()->create();
        $key = $this->enablePublicStats($project);

        $this->get("/api/v1/public/{$key}/metrics")->assertOk();
        expect(DB::table('projects')->whereNotNull('stats_key')->count())->toBe(1);
    });
});
