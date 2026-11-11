<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;

covers(HealthChecksQuery::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    $this->project = Project::factory()->create();
    $this->check = HealthCheck::factory()->for($this->project)->create(['environment' => 'production']);
    $this->check->forceFill(['created_at' => now()->subDays(2)])->save();
    // Down for 30 minutes, from 10:00 to 10:30.
    HealthOutage::factory()->of($this->check)->create(['status' => HealthStatus::Down, 'started_at' => now()->subHours(2), 'ended_at' => now()->subHours(2)->addMinutes(30)]);
    $this->uptime = fn (): ?float => app(HealthChecksQuery::class)->rows($this->project, Period::Day)[0]['uptime'];
});

describe('uptime around maintenance', function () {
    it('leaves out down time inside a window up to its expected end', function (string $environment, string $start, string $expectedEnd, string $end, int $downMinutes) {
        MaintenanceWindow::factory()->of($this->project, $environment)->create([
            'starts_at' => CarbonImmutable::parse("2026-11-11 {$start}"),
            'expected_end_at' => CarbonImmutable::parse("2026-11-11 {$expectedEnd}"),
            'ended_at' => CarbonImmutable::parse("2026-11-11 {$end}"),
        ]);

        expect(($this->uptime)())->toBe(round(1 - $downMinutes / 1440, 5));
    })->with([
        'an outage wholly inside the window' => ['production', '09:50', '10:40', '10:45', 0],
        'a window of every environment' => ['', '09:50', '10:40', '10:45', 0],
        'the part after the expected end counts' => ['production', '09:50', '10:10', '10:35', 20],
        'a window ended early excuses only up to its end' => ['production', '09:50', '11:00', '10:20', 10],
        'a window of another environment' => ['staging', '09:50', '10:40', '10:45', 30],
        'a window after the outage' => ['production', '11:00', '11:30', '11:20', 30],
    ]);

    it('counts an outage outside any window as before', function () {
        expect(($this->uptime)())->toBe(round(1 - 30 / 1440, 5));
    });

    it('stops excusing an open window at its expected end', function () {
        MaintenanceWindow::factory()->of($this->project)->create([
            'starts_at' => now()->subHours(2)->subMinutes(10),
            'expected_end_at' => now()->subHours(2)->addMinutes(5),
        ]);

        // Excused from 10:00 to 10:05, so 25 of the 30 minutes count.
        expect(($this->uptime)())->toBe(round(1 - 25 / 1440, 5));
    });
});
