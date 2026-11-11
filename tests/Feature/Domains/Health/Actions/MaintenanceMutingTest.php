<?php

declare(strict_types=1);

use App\Domains\Alerts\Data\HealthCheckAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendHealthCheckAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

covers(RecordHealthResultAction::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    Queue::fake([SendHealthCheckAlert::class]);
    $this->project = Project::factory()->create(['name' => 'Shop']);
    foreach ([AlertRuleType::HealthDegraded, AlertRuleType::HealthDown, AlertRuleType::HealthRecovered] as $type) {
        AlertRule::factory()->for($this->project)->create(['type' => $type]);
    }
    $this->check = HealthCheck::factory()->for($this->project)->create(['environment' => 'production', 'degrade_after' => 2, 'down_after' => 2, 'recover_after' => 3]);
    // One result a minute, as the SDK's schedule sends them.
    $this->report = function (string $status): void {
        $this->travel(1)->minutes();
        app(RecordHealthResultAction::class)->execute($this->check, new HealthResultData(HealthResultStatus::from($status), 'msg '.$status, host: 'web-1', at: now()->toImmutable()));
    };
});

describe('a check under maintenance', function () {
    it('goes down without an alert, and alerts on its next result once the window has ended', function () {
        $window = MaintenanceWindow::factory()->of($this->project)->create();

        ($this->report)('failed');
        ($this->report)('failed');
        ($this->report)('failed');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Down)
            ->and($this->check->alerted_status)->toBeNull();
        Queue::assertNothingPushed();

        app(EndMaintenanceWindowAction::class)->execute($window);
        ($this->report)('failed');

        Queue::assertPushed(SendHealthCheckAlert::class, 1);
        Queue::assertPushed(SendHealthCheckAlert::class, fn (SendHealthCheckAlert $job): bool => $job->state === HealthCheckAlert::DOWN);
    });

    it('is covered by a window of every environment', function () {
        MaintenanceWindow::factory()->of($this->project, '')->create();

        ($this->report)('failed');
        ($this->report)('failed');

        Queue::assertNothingPushed();
    });

    it('still alerts during a window of another environment', function () {
        MaintenanceWindow::factory()->of($this->project, 'staging')->create();

        ($this->report)('failed');
        ($this->report)('failed');

        Queue::assertPushed(SendHealthCheckAlert::class, fn (SendHealthCheckAlert $job): bool => $job->state === HealthCheckAlert::DOWN);
    });

    it('still alerts during a window that has not started yet', function () {
        MaintenanceWindow::factory()->of($this->project)->planned()->create();

        ($this->report)('failed');
        ($this->report)('failed');

        Queue::assertPushed(SendHealthCheckAlert::class, 1);
    });
});
