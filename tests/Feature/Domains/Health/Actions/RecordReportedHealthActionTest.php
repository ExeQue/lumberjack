<?php

declare(strict_types=1);

use App\Domains\Health\Actions\RecordReportedHealthAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckResult;
use App\Domains\Ingest\Data\Envelope;
use App\Domains\Projects\Models\Project;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(RecordReportedHealthAction::class, HealthResultData::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create();
    $this->event = fn (array $extra = []): array => [
        'type' => 'health', 'slug' => 'database:pgsql', 'status' => 'ok', 'message' => null,
        'metrics' => ['latency_ms' => 1.4], 'duration_ms' => 1.9, 'degrade_after' => 3, 'recover_after' => 4, 'down_after' => 6,
        'trace_id' => str_repeat('a', 32), 'timestamp' => now()->getTimestamp() + 0.5, ...$extra,
    ];
    $this->record = fn (array $event, string $host = 'web-1') => app(RecordReportedHealthAction::class)->execute($this->project, $event, new Envelope('production', 'abc', $host));
});

describe('ingesting health events', function () {
    it('stores a result through the ingest endpoint, with the check made on first sight', function () {
        $this->postJson('/api/ingest', $this->batch([($this->event)()]), ['Authorization' => 'Bearer lj_srv_test-token'])->assertStatus(202);

        $check = HealthCheck::sole();
        $result = HealthCheckResult::sole();

        expect($check->slug)->toBe('database:pgsql')
            ->and($check->environment)->toBe('production')
            ->and($check->degrade_after)->toBe(3)
            ->and($check->recover_after)->toBe(4)
            ->and($check->down_after)->toBe(6)
            ->and($result->host)->toBe('web-1')
            ->and($result->metrics)->toBe(['latency_ms' => 1.4])
            ->and($result->duration_us)->toBe(1900);
    });

    it('keeps one check per slug across hosts, with a state per host', function () {
        ($this->record)(($this->event)(), 'web-1');
        ($this->record)(($this->event)(), 'web-2');

        expect(HealthCheck::count())->toBe(1)
            ->and(HealthCheck::sole()->hosts()->pluck('host')->all())->toBe(['web-1', 'web-2']);
    });

    it('skips an unknown status or a malformed slug', function (array $extra) {
        expect(($this->record)(($this->event)($extra)))->toBeNull()
            ->and(HealthCheck::count())->toBe(0);
    })->with([
        'unknown status' => [['status' => 'great']],
        'uppercase slug' => [['slug' => 'Database']],
        'empty slug' => [['slug' => '']],
        'slug too long' => [['slug' => str_repeat('a', 101)]],
    ]);

    it('clamps what it keeps: at most 20 numeric metrics and a bounded message', function () {
        $metrics = array_combine(array_map(fn (int $i) => "m{$i}", range(1, 30)), range(1, 30));

        ($this->record)(($this->event)(['metrics' => [...$metrics, 'text' => 'x'], 'message' => str_repeat('x', 5000), 'degrade_after' => 99999]));

        $result = HealthCheckResult::sole();
        expect($result->metrics)->toHaveCount(20)
            ->and(mb_strlen((string) $result->message))->toBe(1000)
            ->and(HealthCheck::sole()->degrade_after)->toBe(1000);
    });

    it('uses the default down_after when an older SDK does not send one', function () {
        $event = ($this->event)();
        unset($event['down_after']);
        ($this->record)($event);

        expect(HealthCheck::sole()->down_after)->toBe(HealthCheck::DEFAULT_DOWN_AFTER);
    });

    it('keeps thresholds set in the UI, and brings an archived check back when it reports', function () {
        ($this->record)(($this->event)());
        HealthCheck::sole()->update(['thresholds_overridden' => true, 'degrade_after' => 10, 'down_after' => 20, 'archived_at' => now()]);

        ($this->record)(($this->event)(['degrade_after' => 1, 'down_after' => 1]));

        $check = HealthCheck::sole();
        expect($check->degrade_after)->toBe(10)->and($check->down_after)->toBe(20)->and($check->archived_at)->toBeNull();
    });
});
