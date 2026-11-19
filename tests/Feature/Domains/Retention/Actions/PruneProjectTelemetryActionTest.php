<?php

declare(strict_types=1);

use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Performance\Models\RequestCount;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\PruneApplicationDataAction;
use App\Domains\Retention\Actions\PruneProjectTelemetryAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use Tests\Support\Retention\CreatesRetentionData;

covers(PruneProjectTelemetryAction::class, PruneApplicationDataAction::class);

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

    it('prunes the per-minute request counts with the request runs', function () {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::Requests, 'days' => 7]);

        foreach ([now()->subDays(8), now()->subDays(6)] as $minute) {
            RequestCount::query()->create(['project_id' => $this->project->id, 'minute' => $minute->startOfMinute(), 'method' => 'GET', 'route' => '/', 'status_code' => 200, 'count' => 1, 'duration_us' => 1]);
        }

        $deleted = app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(RequestCount::query()->pluck('minute')->map->toDateString()->all())->toBe([now()->subDays(6)->toDateString()])
            ->and($deleted['requests'])->toBe(1);
    });

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
        RetentionPolicy::factory()->create(['category' => RetentionCategory::HealthOutages, 'days' => null]);
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::HealthOutages, 'days' => null]);
        $this->seedRetentionRow(RetentionCategory::HealthOutages, $this->project, now()->subYears(20));

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect($this->retentionRows(RetentionCategory::HealthOutages))->toBe(1);
    });

    it('keeps a project forever only when the application does', function () {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::HealthOutages, 'days' => null]);
        $this->seedRetentionRow(RetentionCategory::HealthOutages, $this->project, now()->subYears(20));
        $this->seedRetentionRow(RetentionCategory::HealthOutages, $this->project, now()->subDays(30));

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

    it('forgets an AI key once neither AI usage total holds a call of it', function () {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::AiUsageDaily, 'days' => 100]);
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::AiUsageHourly, 'days' => 200]);
        $gone = AiKey::factory()->forProject($this->project)->lastSeen(now()->subDays(201))->create();
        $kept = AiKey::factory()->forProject($this->project)->lastSeen(now()->subDays(150))->create();
        $elsewhere = AiKey::factory()->lastSeen(now()->subDays(500))->create();

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(AiKey::query()->orderBy('id')->pluck('id')->all())->toBe([$kept->id, $elsewhere->id])
            ->and($gone->fresh())->toBeNull();
    });

    it('keeps the AI totals and keys from the start of last month, which AI budgets count', function () {
        $this->travelTo('2026-11-20 12:00:00');

        foreach ([RetentionCategory::AiUsageHourly, RetentionCategory::AiUsageDaily] as $category) {
            RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => $category, 'days' => 2]);
        }

        $hours = [AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-10-01 00:00:00']), AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-09-29 23:00:00'])];
        $days = [AiUsageDaily::factory()->forProject($this->project)->create(['day' => '2026-10-01']), AiUsageDaily::factory()->forProject($this->project)->create(['day' => '2026-09-29'])];
        $keys = [AiKey::factory()->forProject($this->project)->lastSeen(now()->parse('2026-10-01 01:00:00'))->create(), AiKey::factory()->forProject($this->project)->lastSeen(now()->parse('2026-09-29 12:00:00'))->create()];

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        foreach ([$hours, $days, $keys] as [$kept, $pruned]) {
            expect($kept->fresh())->not->toBeNull()
                ->and($pruned->fresh())->toBeNull();
        }
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

        expect(app(PruneApplicationDataAction::class)->execute()['audit_log'])->toBe(1)
            ->and($this->retentionRows(RetentionCategory::AuditLog))->toBe(1);
    });

    it('prunes the server\'s own metrics after a week unless the application says otherwise', function () {
        $this->seedRetentionRow(RetentionCategory::SystemSamples, $this->project, now()->subDays(8));
        $this->seedRetentionRow(RetentionCategory::SystemSamples, $this->project, now()->subDays(6));

        expect(app(PruneApplicationDataAction::class)->execute()['system_samples'])->toBe(1)
            ->and(RetentionCategory::forTeams())->not->toContain(RetentionCategory::SystemSamples);
    });

    it('prunes notification history, read or not, after 90 days unless the application says otherwise', function () {
        $this->seedRetentionRow(RetentionCategory::Notifications, $this->project, now()->subDays(91));
        $this->seedRetentionRow(RetentionCategory::Notifications, $this->project, now()->subDays(89));

        expect(app(PruneApplicationDataAction::class)->execute()['notifications'])->toBe(1);

        RetentionPolicy::factory()->create(['category' => RetentionCategory::Notifications, 'days' => 30]);

        expect(app(PruneApplicationDataAction::class)->execute()['notifications'])->toBe(1)
            ->and($this->retentionRows(RetentionCategory::Notifications))->toBe(0);
    });
});
