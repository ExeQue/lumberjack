<?php

declare(strict_types=1);

use App\Domains\Commands\Models\CommandRun;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Performance\Actions\RecordRequestCountsAction;
use App\Domains\Performance\Models\QueryRun;
use App\Domains\Performance\Models\RequestCount;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Schedule\Models\ScheduledTaskRun;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(RecordRequestCountsAction::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
    $this->auth = ['Authorization' => 'Bearer lj_srv_test-token'];
    $this->counts = fn (array $rows, ?float $at = null): array => ['type' => 'request_counts', 'counts' => $rows, 'from' => $at ?? now()->getTimestamp(), 'to' => $at ?? now()->getTimestamp(), 'timestamp' => $at ?? now()->getTimestamp()];
});

describe('request counts', function () {
    it('adds up one event per request into one row per minute, method, route and status', function () {
        $at = now()->startOfMinute()->addSeconds(10)->getTimestamp();
        $row = ['method' => 'GET', 'route' => '/orders/{order}', 'status' => 200, 'count' => 1, 'duration_ms_sum' => 15.5];

        $this->postJson('/api/ingest', $this->batch([($this->counts)([$row], $at), ($this->counts)([$row], $at + 5)]), $this->auth)->assertStatus(202);
        $this->postJson('/api/ingest', $this->batch([($this->counts)([$row, [...$row, 'status' => 500]], $at + 20)]), $this->auth)->assertStatus(202);

        $ok = RequestCount::query()->where('status_code', 200)->sole();

        expect(RequestCount::count())->toBe(2)
            ->and($ok->count)->toBe(3)
            ->and($ok->duration_us)->toBe(46_500)
            ->and($ok->environment)->toBe('production')
            ->and($ok->minute->getTimestamp())->toBe(now()->startOfMinute()->getTimestamp());
    });

    it('keeps minutes and environments apart', function () {
        $row = ['method' => 'GET', 'route' => '/', 'status' => 200, 'count' => 2, 'duration_ms_sum' => 4];

        $this->postJson('/api/ingest', $this->batch([($this->counts)([$row], now()->subMinutes(2)->getTimestamp())]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->counts)([$row])]), $this->auth);
        $this->postJson('/api/ingest', $this->batch([($this->counts)([$row])], 'staging'), $this->auth);

        expect(RequestCount::count())->toBe(3)
            ->and((int) RequestCount::sum('count'))->toBe(6);
    });

    it('skips a row without a count', function () {
        $this->postJson('/api/ingest', $this->batch([($this->counts)([['method' => 'GET', 'route' => '/'], ['method' => 'GET', 'route' => '/', 'status' => 200, 'count' => 0]])]), $this->auth)->assertStatus(202);

        expect(RequestCount::count())->toBe(0);
    });

    it('refuses counts that are no list, or more rows than an SDK sends', function (mixed $counts) {
        $this->postJson('/api/ingest', $this->batch([['type' => 'request_counts', 'counts' => $counts]]), $this->auth)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('events');
    })->with([
        'missing' => [null],
        'an object' => [['a' => ['count' => 1]]],
        'too many' => [array_fill(0, RecordRequestCountsAction::MAX_ROWS + 1, ['method' => 'GET', 'route' => '/', 'status' => 200, 'count' => 1])],
    ]);
});

describe('sample rates', function () {
    it('stores how each run was sampled, and a rate of 1 for an SDK that does not say', function () {
        $now = now()->getTimestamp();

        $this->postJson('/api/ingest', $this->batch([
            ['type' => 'request', 'trace_id' => 't1', 'timestamp' => $now, 'method' => 'GET', 'route' => '/', 'url' => 'https://x.test/', 'status' => 200, 'duration_ms' => 1500, 'sample_rate' => 0.1, 'sampled' => false],
            ['type' => 'request', 'trace_id' => 't2', 'timestamp' => $now, 'method' => 'GET', 'route' => '/', 'url' => 'https://x.test/', 'status' => 200, 'duration_ms' => 12],
            ['type' => 'query', 'trace_id' => 't1', 'timestamp' => $now, 'sql' => 'select 1', 'duration_ms' => 1, 'sample_rate' => 0.1, 'sampled' => false],
            ['type' => 'job', 'status' => 'processed', 'uuid' => '0d9c7a1e-1111-4222-8333-944455556666', 'name' => 'App\\Jobs\\Foo', 'attempt' => 1, 'timestamp' => $now, 'sample_rate' => 0.25, 'sampled' => true],
            ['type' => 'command', 'status' => 'finished', 'uuid' => '0d9c7a1e-1111-4222-8333-944455556667', 'name' => 'app:sync', 'exit_code' => 0, 'timestamp' => $now, 'sample_rate' => 0.5, 'sampled' => false],
            ['type' => 'schedule', 'status' => 'finished', 'uuid' => '0d9c7a1e-1111-4222-8333-944455556668', 'name' => 'app:prune', 'timestamp' => $now, 'sample_rate' => 0.2, 'sampled' => true],
        ]), $this->auth)->assertStatus(202);

        $slow = RequestRun::query()->where('trace_id', 't1')->sole();
        $plain = RequestRun::query()->where('trace_id', 't2')->sole();

        expect([$slow->sample_rate, $slow->sampled])->toBe([0.1, false])
            ->and([$plain->sample_rate, $plain->sampled])->toBe([1.0, true])
            ->and([QueryRun::sole()->sample_rate, QueryRun::sole()->sampled])->toBe([0.1, false])
            ->and(JobRun::sole()->sample_rate)->toBe(0.25)
            ->and([CommandRun::sole()->sample_rate, CommandRun::sole()->sampled])->toBe([0.5, false])
            ->and(ScheduledTaskRun::sole()->sample_rate)->toBe(0.2);
    });
});
