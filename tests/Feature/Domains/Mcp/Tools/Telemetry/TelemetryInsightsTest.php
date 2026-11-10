<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Telemetry\GetMetricSeries;
use App\Domains\Mcp\Tools\Telemetry\GetRelease;
use App\Domains\Mcp\Tools\Telemetry\ListBrowserErrors;
use App\Domains\Mcp\Tools\Telemetry\ListHosts;
use App\Domains\Mcp\Tools\Telemetry\ListQueues;
use App\Domains\Mcp\Tools\Telemetry\ListReleases;
use App\Domains\Mcp\Tools\Telemetry\SearchProject;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Models\User;
use Tests\Support\Mcp\CallsMcpTools;

covers(ListBrowserErrors::class, ListReleases::class, GetRelease::class, GetMetricSeries::class, ListQueues::class, ListHosts::class, SearchProject::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->travelTo(now()->startOfHour()->addMinutes(30));
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->blog = Project::factory()->for($this->project->team)->create(['slug' => 'blog']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->token = McpToken::factory()->for($this->project->team)->withToken('lj_mcp_secret')->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach([$this->project->id, $this->blog->id]);
    $this->call = fn (string $tool, array $arguments = []): array => $this->toolResult($this->callTool('lj_mcp_secret', $tool, ['project' => 'shop', ...$arguments])->assertOk());
});

describe('reading telemetry over MCP', function () {
    it('lists browser errors', function () {
        $issue = Issue::factory()->for($this->project)->fromBrowser()->create();
        Occurrence::factory()->for($issue)->count(2)->create();

        $result = ($this->call)('list_browser_errors');

        expect($result['occurrences'])->toBe(2)
            ->and($result['issues'][0]['id'])->toBe($issue->uuid);
    });

    it('lists releases and shows one with its new and regressed issues', function () {
        RequestRun::factory()->for($this->project)->create(['release' => 'v2', 'duration_us' => 120_000, 'sampled' => true]);
        $new = Issue::factory()->for($this->project)->create(['first_release' => 'v2']);
        Occurrence::factory()->for($new)->create(['release' => 'v2']);
        $regressed = Issue::factory()->for($this->project)->regressed()->create(['first_seen_at' => now()->subMonths(2)]);
        IssueEvent::factory()->for($regressed)->regressed('v2')->create();

        expect(($this->call)('list_releases')['releases'][0])->toMatchArray(['release' => 'v2', 'p95_us' => 120_000, 'new_issues' => 1, 'regressions' => 1]);

        $result = ($this->call)('get_release', ['release' => 'v2']);

        expect(array_column($result['issues']['new'], 'id'))->toBe([$new->uuid])
            ->and(array_column($result['issues']['regressed'], 'id'))->toBe([$regressed->uuid])
            ->and($this->wasRefused($this->callTool('lj_mcp_secret', 'get_release', ['project' => 'shop', 'release' => 'v9'])))->toBeTrue();
    });

    it('returns a metric series with durations in microseconds and refuses an unknown metric', function () {
        RequestRun::factory()->for($this->project)->create(['duration_us' => 250_000, 'sampled' => true, 'occurred_at' => now()->subMinutes(5)]);

        $result = ($this->call)('get_metric_series', ['metric' => 'p95', 'interval' => '1h']);

        expect($result['unit'])->toBe('us')
            ->and(collect($result['points'])->pluck('value')->filter()->values()->all())->toBe([250_000])
            ->and($this->wasRefused($this->callTool('lj_mcp_secret', 'get_metric_series', ['project' => 'shop', 'metric' => 'nope'])))->toBeTrue();
    });

    it('lists queues with their depth', function () {
        QueueSample::factory()->for($this->project)->forQueue('emails')->waiting(12, 30)->create();

        expect(($this->call)('list_queues')['queues'][0])->toMatchArray(['queue' => 'emails', 'size' => 12, 'oldest_age_s' => 30]);
    });

    it('lists hosts with their latest sample', function () {
        $agent = ProjectAgent::factory()->for($this->project)->onHost('web-1')->create();
        HostSample::factory()->for($agent, 'agent')->create();

        $host = ($this->call)('list_hosts')['hosts'][0];

        expect($host)->toMatchArray(['host' => 'web-1', 'online' => true])
            ->and($host['sample']['cpu_cores'])->not->toBeNull()
            ->and($host)->not->toHaveKey('token');
    });

    it('searches a project', function () {
        Issue::factory()->for($this->project)->create(['title' => 'PaymentDeclinedException']);

        expect(($this->call)('search_project', ['q' => 'Payment'])['results'][0])->toMatchArray(['group' => 'issues', 'label' => 'PaymentDeclinedException']);
    });
});

describe('a project without telemetry', function () {
    it('refuses every tool there', function (string $tool, array $arguments) {
        $this->token->projects()->updateExistingPivot($this->project->id, ['permissions' => McpPermissions::fromArray(['telemetry' => ['level' => 'none']])]);

        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, ['project' => 'shop', ...$arguments])))->toBeTrue();
    })->with([
        'browser errors' => ['list_browser_errors', []],
        'releases' => ['list_releases', []],
        'one release' => ['get_release', ['release' => 'v2']],
        'a metric series' => ['get_metric_series', ['metric' => 'requests']],
        'queues' => ['list_queues', []],
        'hosts' => ['list_hosts', []],
        'search' => ['search_project', ['q' => 'Payment']],
    ]);
});
