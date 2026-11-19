<?php

declare(strict_types=1);

use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Metrics\Data\MetricFilter;
use App\Domains\Metrics\Data\MetricWindow;
use App\Domains\Metrics\Enums\Metric;
use App\Domains\Metrics\Enums\MetricGroup;
use App\Domains\Metrics\Queries\MetricSeriesQuery;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\HostSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectAgent;
use App\Domains\Shared\Enums\Period;
use Illuminate\Support\Facades\DB;
use Tests\Support\Performance\CreatesRequestCounts;

covers(MetricSeriesQuery::class, MetricWindow::class);

uses(CreatesRequestCounts::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->project = Project::factory()->create();
    $this->day = MetricWindow::forPeriod(Period::Day);
    $this->value = fn (Metric $metric, array $filter = []): ?float => app(MetricSeriesQuery::class)->value($metric, new MetricFilter([$this->project->id], ...$filter), $this->day);
});

describe('request metrics', function () {
    it('weighs runs by their sample rate when a release is asked for, as the counts carry none', function () {
        $this->createRequestCount($this->project, 1000);
        RequestRun::factory()->for($this->project)->count(4)->create(['release' => 'v2', 'sample_rate' => 0.5, 'occurred_at' => now()->subHour()]);
        RequestRun::factory()->for($this->project)->create(['release' => 'v1', 'occurred_at' => now()->subHour()]);

        expect(($this->value)(Metric::Requests))->toEqual(1000)
            ->and(($this->value)(Metric::Requests, ['release' => 'v2']))->toEqual(8);
    });

    it('scores Apdex like the routes page', function () {
        $this->createRequestCount($this->project, 8);
        $this->createRequestCount($this->project, 2, 500);
        // Of the picked runs other than 5xx: two satisfied, one tolerated, one frustrated.
        foreach ([100_000, 200_000, 1_000_000, 5_000_000] as $duration) {
            RequestRun::factory()->for($this->project)->create(['duration_us' => $duration, 'occurred_at' => now()->subHour()]);
        }

        // 8 of 10 requests are not 5xx, scored (2 + 1/2) / 4.
        expect(($this->value)(Metric::Apdex))->toEqual(0.5);
    });

    it('matches a route pattern with a wildcard', function () {
        $this->createRequestCount($this->project, 3, route: '/api/orders');
        $this->createRequestCount($this->project, 4, route: '/api/users');
        $this->createRequestCount($this->project, 5, route: '/checkout');

        expect(($this->value)(Metric::Requests, ['route' => '/api/*']))->toEqual(7)
            ->and(($this->value)(Metric::Requests, ['route' => '/checkout']))->toEqual(5);
    });
});

describe('gauges', function () {
    it('adds up the latest size of each queue', function () {
        $sample = fn (string $queue, int $size, int $minutesAgo) => QueueSample::query()->create(['project_id' => $this->project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => $queue, 'size' => $size, 'sampled_at' => now()->subMinutes($minutesAgo)]);
        $sample('mail', 50, 10);
        $sample('mail', 5, 1);
        $sample('default', 3, 2);

        expect(($this->value)(Metric::QueueSize))->toEqual(8)
            ->and(($this->value)(Metric::QueueSize, ['queue' => 'mail']))->toEqual(5);
    });

    it('takes the busiest host as it reported last', function () {
        foreach ([[0.5, 0.95], [0.9, 0.2]] as [$old, $now]) {
            $agent = ProjectAgent::factory()->for($this->project)->create();
            HostSample::query()->create(['project_agent_id' => $agent->id, 'memory_used_bytes' => (int) ($old * 100), 'memory_total_bytes' => 100, 'sampled_at' => now()->subMinutes(10)]);
            HostSample::query()->create(['project_agent_id' => $agent->id, 'memory_used_bytes' => (int) ($now * 100), 'memory_total_bytes' => 100, 'sampled_at' => now()->subMinute()]);
        }

        expect(($this->value)(Metric::MemoryUsed))->toEqual(0.95);
    });
});

describe('issues and jobs', function () {
    it('counts occurrences, distinct users and new issues', function () {
        $issue = Issue::factory()->for($this->project)->create(['first_seen_at' => now()->subHour()]);
        Occurrence::factory()->for($issue)->count(3)->create(['user_id' => 'u1', 'occurred_at' => now()->subHour()]);
        Occurrence::factory()->for($issue)->create(['user_id' => 'u2', 'occurred_at' => now()->subHour()]);

        expect(($this->value)(Metric::Occurrences))->toEqual(4)
            ->and(($this->value)(Metric::Users))->toEqual(2)
            ->and(($this->value)(Metric::NewIssues))->toEqual(1);
    });

    it('counts failed job runs per queue', function () {
        JobRun::factory()->for($this->project)->failed()->count(2)->create(['queue' => 'mail', 'created_at' => now()->subHour()]);
        JobRun::factory()->for($this->project)->failed()->create(['queue' => 'default', 'created_at' => now()->subHour()]);
        JobRun::factory()->for($this->project)->create(['created_at' => now()->subHour()]);

        $series = app(MetricSeriesQuery::class)->series(Metric::JobFailures, new MetricFilter([$this->project->id], groupBy: MetricGroup::Queue), $this->day);

        expect(($this->value)(Metric::JobFailures))->toEqual(3)
            ->and(collect($series)->groupBy('series')->map->sum('value')->all())->toEqual(['mail' => 2, 'default' => 1]);
    });

    it('fills empty buckets of a count with zero and leaves other metrics out there', function () {
        $requests = app(MetricSeriesQuery::class)->series(Metric::Requests, new MetricFilter([$this->project->id]), $this->day);
        $p95 = app(MetricSeriesQuery::class)->series(Metric::P95, new MetricFilter([$this->project->id]), $this->day);

        expect($requests)->toHaveCount(24)
            ->and(collect($requests)->sum('value'))->toEqual(0)
            ->and($p95)->toBe([])
            ->and(($this->value)(Metric::P95))->toBeNull();
    });
});

describe('activity', function () {
    it('sums the hourly counts of the matching activity, split by activity', function () {
        ActivityCount::factory()->forProject($this->project)->named('billing', 'invoice.paid')->create(['hour' => now()->subHours(2), 'count' => 4]);
        ActivityCount::factory()->forProject($this->project)->named('billing', 'invoice.sent')->create(['hour' => now()->subHour(), 'count' => 2]);
        ActivityCount::factory()->forProject($this->project)->named('users', 'user.signed_up')->create(['hour' => now()->subHour(), 'count' => 7]);
        ActivityCount::factory()->forProject($this->project)->named('billing', 'invoice.paid')->create(['hour' => now()->subDays(2), 'count' => 100]);

        $series = app(MetricSeriesQuery::class)->series(Metric::Activity, new MetricFilter([$this->project->id], activity: 'billing:*', groupBy: MetricGroup::Activity), $this->day);

        expect(($this->value)(Metric::Activity))->toEqual(13)
            ->and(($this->value)(Metric::Activity, ['activity' => 'billing:*']))->toEqual(6)
            ->and(($this->value)(Metric::Activity, ['activity' => 'users:user.signed_up']))->toEqual(7)
            ->and(collect($series)->groupBy('series')->map->sum('value')->all())->toEqual(['billing:invoice.paid' => 4, 'billing:invoice.sent' => 2]);
    });

    it('counts the activity itself when the buckets are shorter than an hour', function () {
        Activity::factory()->forProject($this->project)->count(3)->create(['occurred_at' => now()->subMinutes(10)]);
        Activity::factory()->forProject($this->project)->create(['occurred_at' => now()->subHours(2)]);

        $window = MetricWindow::between(now()->subHour(), now());

        expect(app(MetricSeriesQuery::class)->value(Metric::Activity, new MetricFilter([$this->project->id]), $window))->toEqual(3);
    });
});

describe('web vitals', function () {
    it('takes the 75th percentile of each vital', function (Metric $metric, string $column, array $values, float $expected) {
        foreach ($values as $value) {
            DB::table('web_vitals')->insert(['project_id' => $this->project->id, 'route' => '/', $column => $value, 'occurred_at' => now()->subHour()]);
        }

        expect(($this->value)($metric))->toEqual($expected);
    })->with([
        'LCP in ms' => [Metric::LcpP75, 'lcp_us', [1_000_000, 2_000_000, 3_000_000, 4_000_000], 3000.0],
        'INP in ms' => [Metric::InpP75, 'inp_us', [100_000, 200_000, 300_000, 400_000], 300.0],
        'CLS as is' => [Metric::ClsP75, 'cls', [0.1, 0.2, 0.3, 0.4], 0.3],
    ]);
});

describe('windows', function () {
    it('picks an automatic bucket from its steps, at most a day', function () {
        expect(MetricWindow::between(now()->subHour(), now())->bucketSeconds)->toBe(60)
            ->and(MetricWindow::between(now()->subDays(7), now())->interval())->toBe('1h')
            ->and(MetricWindow::between(now()->subYears(2), now())->bucketSeconds)->toBe(86400);
    });
});

describe('AI usage', function () {
    beforeEach(function () {
        AiPrice::factory()->forTeamOf($this->project)->costing(3, 15)->create();
        $this->split = fn (Metric $metric, MetricGroup $group, array $filter = []): array => collect(app(MetricSeriesQuery::class)
            ->series($metric, new MetricFilter([$this->project->id], ...$filter, groupBy: $group), $this->day))
            ->groupBy('series')->map->sum('value')->all();
    });

    it('serves the same tokens, calls and cost per hour as the AI pages', function () {
        $key = AiKey::factory()->forProject($this->project)->create();
        AiUsageHourly::factory()->forKey($key)->create(['hour' => now()->subHours(3), 'calls' => 4, 'errors' => 1, 'input_tokens' => 1_000_000, 'output_tokens' => 100_000]);
        AiUsageHourly::factory()->forKey($key)->create(['hour' => now()->subHour(), 'input_tokens' => 2_000_000, 'output_tokens' => 0, 'cache_read_tokens' => 500_000]);
        $total = app(AiCostQuery::class)->total($this->project, AiCostQuery::HOURLY, $this->day->from->startOfHour());
        $series = fn (Metric $metric): array => app(MetricSeriesQuery::class)->series($metric, new MetricFilter([$this->project->id]), $this->day);

        $cost = collect($series(Metric::AiCost));

        expect($cost)->toHaveCount(24)
            ->and($cost->sum('value'))->toEqualWithDelta($total['cost'], 0.000001)
            ->and($cost->firstWhere('t', now()->subHours(3)->format('Y-m-d\TH:i:s\Z'))['value'])->toEqualWithDelta(4.5, 0.000001)
            ->and(collect($series(Metric::AiTokensInput))->sum('value'))->toEqual($total['input_tokens'])
            ->and(collect($series(Metric::AiTokensCacheRead))->sum('value'))->toEqual($total['cache_read_tokens'])
            ->and(collect($series(Metric::AiCalls))->sum('value'))->toEqual($total['calls'])
            ->and(($this->value)(Metric::AiErrors))->toEqual(1)
            ->and(($this->value)(Metric::AiCost))->toEqualWithDelta($total['cost'], 0.000001);
    });

    it('splits cost by who pays, or keeps one payer\'s share', function () {
        $ours = AiKey::factory()->forProject($this->project)->create();
        $tenants = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create();
        AiUsageHourly::factory()->forKey($ours)->create(['hour' => now()->subHour(), 'input_tokens' => 1_000_000, 'output_tokens' => 0]);
        AiUsageHourly::factory()->forKey($tenants)->create(['hour' => now()->subHour(), 'input_tokens' => 2_000_000, 'output_tokens' => 0]);

        expect(($this->split)(Metric::AiCost, MetricGroup::Payer))->toEqual(['us' => 3.0, 'customer' => 6.0])
            ->and(($this->value)(Metric::AiCost, ['payer' => AiPayer::Us]))->toEqualWithDelta(3.0, 0.000001)
            ->and(($this->value)(Metric::AiCost, ['payer' => AiPayer::Customer]))->toEqualWithDelta(6.0, 0.000001)
            ->and(($this->value)(Metric::AiCost))->toEqualWithDelta(9.0, 0.000001);
    });

    it('counts only the projects asked for, of its team or another', function () {
        $sibling = Project::factory()->create(['team_id' => $this->project->team_id]);
        $foreign = Project::factory()->create();
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->subHour(), 'calls' => 2]);
        AiUsageHourly::factory()->forProject($sibling)->create(['hour' => now()->subHour(), 'calls' => 30]);
        AiUsageHourly::factory()->forProject($foreign)->create(['hour' => now()->subHour(), 'calls' => 400]);

        expect(($this->value)(Metric::AiCalls))->toEqual(2)
            ->and(app(MetricSeriesQuery::class)->value(Metric::AiCalls, new MetricFilter([$this->project->id, $sibling->id]), $this->day))->toEqual(32);
    });

    it('names a key by its name or short id, never its fingerprint, and filters by either', function () {
        $named = AiKey::factory()->forProject($this->project)->create(['name' => 'checkout bot']);
        $plain = AiKey::factory()->forProject($this->project)->create();
        AiUsageHourly::factory()->forKey($named)->create(['hour' => now()->subHour(), 'calls' => 3]);
        AiUsageHourly::factory()->forKey($plain)->create(['hour' => now()->subHour(), 'calls' => 2]);

        expect(($this->split)(Metric::AiCalls, MetricGroup::Key))->toEqual(['checkout bot' => 3, $plain->shortId() => 2])
            ->and(($this->value)(Metric::AiCalls, ['key' => 'checkout bot']))->toEqual(3)
            ->and(($this->value)(Metric::AiCalls, ['key' => $plain->shortId()]))->toEqual(2)
            ->and(($this->value)(Metric::AiCalls, ['key' => $plain->fingerprint]))->toEqual(0);
    });

    it('filters and splits by provider, model and owner', function () {
        $tenants = AiKey::factory()->forProject($this->project)->ownedBy('tenant', 7)->create();
        AiUsageHourly::factory()->forKey($tenants)->create(['hour' => now()->subHour(), 'calls' => 5]);
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->subHour(), 'provider' => 'openai', 'model' => 'gpt-5', 'calls' => 1]);

        expect(($this->split)(Metric::AiCalls, MetricGroup::Model))->toEqual(['claude-sonnet-4-5' => 5, 'gpt-5' => 1])
            ->and(($this->split)(Metric::AiCalls, MetricGroup::Owner))->toEqual(['tenant:7' => 5, MetricSeriesQuery::NONE => 1])
            ->and(($this->value)(Metric::AiCalls, ['model' => 'CLAUDE-*']))->toEqual(5)
            ->and(($this->value)(Metric::AiCalls, ['provider' => 'openai']))->toEqual(1)
            ->and(($this->value)(Metric::AiCalls, ['owner' => 'tenant:7']))->toEqual(5);
    });

    it('counts actors per day from the daily totals', function () {
        AiUsageDaily::factory()->forProject($this->project)->byActor('user', 1)->create(['calls' => 4]);
        AiUsageDaily::factory()->forProject($this->project)->byActor('user', 2)->create(['calls' => 1]);
        $filter = new MetricFilter([$this->project->id], groupBy: MetricGroup::Actor);
        $window = MetricWindow::between(now()->subDay(), now(), 300);

        $points = app(MetricSeriesQuery::class)->series(Metric::AiCalls, $filter, $window);

        expect(app(MetricSeriesQuery::class)->window(Metric::AiCalls, $filter, $window)->interval())->toBe('1d')
            ->and(app(MetricSeriesQuery::class)->window(Metric::AiCalls, new MetricFilter([$this->project->id]), $window)->interval())->toBe('1h')
            ->and(collect($points)->groupBy('series')->map->sum('value')->all())->toEqual(['user:1' => 4, 'user:2' => 1])
            ->and(($this->value)(Metric::AiCalls, ['actor' => 'user:2']))->toEqual(1);
    });
});
