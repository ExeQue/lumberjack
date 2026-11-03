<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\PruneAuditLogAction;
use App\Domains\Retention\Actions\PruneProjectTelemetryAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use Tests\Support\Retention\CreatesRetentionData;

covers(PruneProjectTelemetryAction::class, PruneAuditLogAction::class);

uses(CreatesRetentionData::class);



beforeEach(function () {
    $this->project = Project::factory()->create();
});

describe('pruning', function () {
    it('prunes each data type by its own retention', function (RetentionCategory $kept) {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => $kept, 'days' => 3650]);

        foreach (RetentionCategory::forTeams() as $category) {
            $this->seedRetentionRow($category, $this->project, now()->subDays(400));
            $this->seedRetentionRow($category, $this->project, now()->subHour());
        }

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        foreach (RetentionCategory::forTeams() as $category) {
            expect($this->retentionRows($category))->toBe($category === $kept ? 2 : 1, $category->value);
        }
    })->with(fn () => array_map(fn (RetentionCategory $c): array => [$c], RetentionCategory::forTeams()));

    it('follows the built-in default of queue and host samples, which no longer prune themselves', function () {
        foreach ([RetentionCategory::QueueSamples, RetentionCategory::HostSamples] as $category) {
            $this->seedRetentionRow($category, $this->project, now()->subDays($category->defaultDays() + 1));
            $this->seedRetentionRow($category, $this->project, now()->subDays($category->defaultDays() - 1));
        }

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect($this->retentionRows(RetentionCategory::QueueSamples))->toBe(1)
            ->and($this->retentionRows(RetentionCategory::HostSamples))->toBe(1);
    });

    it('lets the team set queue and host samples', function () {
        RetentionPolicy::factory()->create(['team_id' => $this->project->team_id, 'category' => RetentionCategory::HostSamples, 'days' => 2]);
        $this->seedRetentionRow(RetentionCategory::HostSamples, $this->project, now()->subDays(3));

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect($this->retentionRows(RetentionCategory::HostSamples))->toBe(0);
    });

    it('never deletes what is kept forever', function () {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::HealthOutages, 'days' => null]);
        $this->seedRetentionRow(RetentionCategory::HealthOutages, $this->project, now()->subYears(20));

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect($this->retentionRows(RetentionCategory::HealthOutages))->toBe(1);
    });

    it('only touches the project it prunes', function () {
        $other = Project::factory()->create();
        $this->seedRetentionRow(RetentionCategory::HostSamples, $other, now()->subDays(30));
        $this->seedRetentionRow(RetentionCategory::AlertSends, $other, now()->subDays(300));

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect($this->retentionRows(RetentionCategory::HostSamples))->toBe(1)
            ->and($this->retentionRows(RetentionCategory::AlertSends))->toBe(1);
    });

    it('keeps a failed job run as long as occurrences are kept', function () {
        JobRun::factory()->for($this->project)->failed()->create(['created_at' => now()->subDays(40)]);
        JobRun::factory()->for($this->project)->create(['created_at' => now()->subDays(40)]);

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(JobRun::sole()->status->value)->toBe('failed');
    });

    it('keeps the issue when its occurrences age out', function () {
        $issue = Issue::factory()->for($this->project)->create();
        Occurrence::factory()->for($issue)->create(['occurred_at' => now()->subDays(100)]);

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(Occurrence::count())->toBe(0)->and($issue->fresh())->not->toBeNull();
    });

    it('prunes the audit log by the application value', function () {
        RetentionPolicy::factory()->create(['category' => RetentionCategory::AuditLog, 'days' => 10]);
        $this->seedRetentionRow(RetentionCategory::AuditLog, $this->project, now()->subDays(11));
        $this->seedRetentionRow(RetentionCategory::AuditLog, $this->project, now()->subDays(9));

        expect(app(PruneAuditLogAction::class)->execute())->toBe(1)
            ->and($this->retentionRows(RetentionCategory::AuditLog))->toBe(1);
    });
});
