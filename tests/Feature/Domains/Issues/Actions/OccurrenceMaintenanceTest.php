<?php

declare(strict_types=1);

use App\Domains\Alerts\Jobs\SendIssueAlerts;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

covers(RecordOccurrenceAction::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    Queue::fake([SendIssueAlerts::class]);
    $this->project = Project::factory()->create();
    $this->record = fn (string $environment = 'production', ?CarbonImmutable $at = null): Occurrence => app(RecordOccurrenceAction::class)->execute(
        $this->project,
        new IssueSignal(IssueSource::Server, 'RuntimeException', 'Boom', 'app/Foo.php', 12, [], [], $at ?? now()->toImmutable(), environment: $environment),
    );
});

describe('errors during maintenance', function () {
    it('flags an occurrence in a window of its environment, and the new issue it opens', function () {
        MaintenanceWindow::factory()->of($this->project)->create();

        $occurrence = ($this->record)();

        expect($occurrence->under_maintenance)->toBeTrue()
            ->and($occurrence->issue->refresh()->under_maintenance)->toBeTrue();
    });

    it('keeps the issue flagged while every occurrence came in during a window', function () {
        MaintenanceWindow::factory()->of($this->project, '')->create();

        ($this->record)();
        $this->travel(1)->minutes();
        $occurrence = ($this->record)();

        expect($occurrence->issue->refresh())->under_maintenance->toBeTrue()->occurrences_count->toBe(2);
    });

    it('clears the issue\'s flag on a later occurrence outside a window', function () {
        $window = MaintenanceWindow::factory()->of($this->project)->create();
        $first = ($this->record)();

        app(EndMaintenanceWindowAction::class)->execute($window);
        $this->travel(1)->minutes();
        $second = ($this->record)();

        expect($second->issue_id)->toBe($first->issue_id)
            ->and($first->refresh()->under_maintenance)->toBeTrue()
            ->and($second->under_maintenance)->toBeFalse()
            ->and($second->issue->refresh()->under_maintenance)->toBeFalse();
    });

    it('flags an occurrence by when it happened, not when it came in', function () {
        MaintenanceWindow::factory()->of($this->project)->ended()->create();

        expect(($this->record)(at: now()->subMinutes(45)->toImmutable())->under_maintenance)->toBeTrue()
            ->and(($this->record)()->under_maintenance)->toBeFalse();
    });

    it('does not flag an occurrence of another environment', function () {
        MaintenanceWindow::factory()->of($this->project, 'staging')->create();

        $occurrence = ($this->record)();

        expect($occurrence->under_maintenance)->toBeFalse()
            ->and($occurrence->issue->refresh()->under_maintenance)->toBeFalse();
    });
});
