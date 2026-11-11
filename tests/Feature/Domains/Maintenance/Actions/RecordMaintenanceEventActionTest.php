<?php

declare(strict_types=1);

use App\Domains\Ingest\Actions\ProcessIngestBatchAction;
use App\Domains\Ingest\Http\Requests\IngestBatchRequest;
use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Actions\RecordMaintenanceEventAction;
use App\Domains\Maintenance\Actions\StartMaintenanceAction;
use App\Domains\Maintenance\Enums\MaintenanceSource;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Tests\Support\Ingest\BuildsIngestPayloads;

covers(RecordMaintenanceEventAction::class, StartMaintenanceAction::class, ProcessIngestBatchAction::class, IngestBatchRequest::class);

uses(BuildsIngestPayloads::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    $this->project = Project::factory()->withToken('lj_srv_test-token')->create(['maintenance_expected_minutes' => 15, 'allowed_origins' => ['https://app.example.test']]);
    $this->send = fn (array $event, string $environment = 'production') => $this->postJson('/api/ingest', $this->batch([
        ['type' => 'maintenance', 'timestamp' => now()->getTimestamp(), ...$event],
    ], $environment), ['Authorization' => 'Bearer lj_srv_test-token'])->assertStatus(202);
});

describe('a start event', function () {
    it('opens a reported window for the batch\'s environment, expected to end when the app says or after the project default', function (Closure $fields, int $minutes) {
        ($this->send)(['state' => 'start', 'message' => 'Migrating', ...$fields()], 'staging');

        $window = MaintenanceWindow::query()->sole();
        expect($window->project_id)->toBe($this->project->id)
            ->and($window->environment)->toBe('staging')
            ->and($window->source)->toBe(MaintenanceSource::Reported)
            ->and($window->message)->toBe('Migrating')
            ->and($window->starts_at->getTimestamp())->toBe(now()->getTimestamp())
            ->and($window->expected_end_at->getTimestamp())->toBe(now()->addMinutes($minutes)->getTimestamp())
            ->and($window->ended_at)->toBeNull();
    })->with([
        'expected minutes' => [fn () => ['expected_minutes' => 45], 45],
        'an expected until' => [fn () => ['expected_until' => now()->addHours(2)->toIso8601String()], 120],
        'neither, so the project default' => [fn () => [], 15],
    ]);

    it('keeps the open window on a second start, with the new message and expected end', function () {
        ($this->send)(['state' => 'start', 'message' => 'Migrating', 'expected_minutes' => 10]);
        $this->travel(5)->minutes();
        ($this->send)(['state' => 'start', 'message' => 'Still migrating', 'expected_minutes' => 30]);

        $window = MaintenanceWindow::query()->sole();
        expect($window->message)->toBe('Still migrating')
            ->and($window->starts_at->getTimestamp())->toBe(now()->subMinutes(5)->getTimestamp())
            ->and($window->expected_end_at->getTimestamp())->toBe(now()->addMinutes(30)->getTimestamp());
    });

    it('ignores a field it does not know', function () {
        ($this->send)(['state' => 'start', 'expected_minutes' => 20, 'colour' => 'blue']);

        expect(MaintenanceWindow::query()->sole()->expected_end_at->getTimestamp())->toBe(now()->addMinutes(20)->getTimestamp());
    });

    it('is not taken from a browser', function () {
        app(ProcessIngestBatchAction::class)->execute($this->project, $this->batch([['type' => 'maintenance', 'state' => 'start']]), fromBrowser: true);

        expect(MaintenanceWindow::query()->count())->toBe(0);
    });
});

describe('an end event', function () {
    it('closes the open window of its environment only', function () {
        $production = MaintenanceWindow::factory()->of($this->project)->create();
        $staging = MaintenanceWindow::factory()->of($this->project, 'staging')->create();

        ($this->send)(['state' => 'end']);

        expect($production->refresh()->ended_at?->getTimestamp())->toBe(now()->getTimestamp())
            ->and($staging->refresh()->ended_at)->toBeNull();
    });

    it('ignores a start held back past the end that followed it', function () {
        $startedAt = now()->getTimestamp();
        $this->travel(2)->minutes();
        ($this->send)(['state' => 'start']);
        ($this->send)(['state' => 'end']);

        // The agent delivers the first start late.
        ($this->send)(['state' => 'start', 'timestamp' => $startedAt]);

        expect(MaintenanceWindow::query()->whereNotEnded()->count())->toBe(0);
    });

    it('ignores the only start when the agent delivers it after its end', function () {
        $startedAt = now()->getTimestamp();
        $this->travel(5)->minutes();
        ($this->send)(['state' => 'end']);

        ($this->send)(['state' => 'start', 'timestamp' => $startedAt]);

        expect(MaintenanceWindow::query()->count())->toBe(0);
    });

    it('opens a window although a planned one was cancelled for later', function () {
        $planned = MaintenanceWindow::factory()->of($this->project)->planned()->create();
        app(EndMaintenanceWindowAction::class)->execute($planned);

        ($this->send)(['state' => 'start']);

        expect(MaintenanceWindow::query()->whereNotEnded()->count())->toBe(1);
    });

    it('takes a new start after an end in the same second', function () {
        ($this->send)(['state' => 'start', 'timestamp' => now()->getTimestamp()]);
        ($this->send)(['state' => 'end', 'timestamp' => now()->getTimestamp() + 0.2]);
        ($this->send)(['state' => 'start', 'timestamp' => now()->getTimestamp() + 0.4]);

        expect(MaintenanceWindow::query()->whereNotEnded()->count())->toBe(1);
    });

    it('leaves open a window that started after the end', function () {
        ($this->send)(['state' => 'start']);
        ($this->send)(['state' => 'end', 'timestamp' => now()->subMinute()->getTimestamp()]);

        expect(MaintenanceWindow::query()->sole()->ended_at)->toBeNull();
    });

    it('does nothing with no window open', function () {
        $ended = MaintenanceWindow::factory()->of($this->project)->ended()->create();
        $endedAt = $ended->ended_at->getTimestamp();

        ($this->send)(['state' => 'end']);

        expect(MaintenanceWindow::query()->count())->toBe(1)
            ->and($ended->refresh()->ended_at->getTimestamp())->toBe($endedAt);
    });
});

describe('the heartbeat', function () {
    it('tells an agent it takes maintenance events', function () {
        $this->postJson('/api/ingest/heartbeat', ['host' => 'web-1'], ['Authorization' => 'Bearer lj_srv_test-token'])
            ->assertOk()
            ->assertJsonPath('features', fn (array $features): bool => in_array('maintenance', $features, true));
    });
});
