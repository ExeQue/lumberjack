<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Jobs\Models\JobRun;
use App\Domains\Logs\Models\LogEntry;
use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\PruneProjectTelemetryAction;
use App\Domains\Retention\Actions\ResolveRetentionDaysAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Support\Facades\DB;

covers(PruneProjectTelemetryAction::class, ResolveRetentionDaysAction::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
});

describe('resolving retention', function () {
    it('lets the most specific setting win', function () {
        RetentionPolicy::factory()->create(['team_id' => $this->project->team_id, 'category' => RetentionCategory::Jobs, 'days' => 20]);
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::Jobs, 'days' => 10]);
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::Jobs, 'job_class' => 'App\\Jobs\\Charge', 'days' => 365]);

        $resolve = app(ResolveRetentionDaysAction::class);

        expect($resolve->execute($this->project, RetentionCategory::Jobs, 'App\\Jobs\\Charge'))->toBe(365)
            ->and($resolve->execute($this->project, RetentionCategory::Jobs, 'App\\Jobs\\Other'))->toBe(10)
            ->and($resolve->execute($this->project, RetentionCategory::Logs))->toBe(RetentionCategory::Logs->defaultDays());
    });
});

describe('pruning', function () {
    it('deletes raw rows past retention and keeps the rest', function () {
        RequestRun::factory()->for($this->project)->create(['occurred_at' => now()->subDays(20)]);
        RequestRun::factory()->for($this->project)->create(['occurred_at' => now()->subDays(2)]);
        $issue = Issue::factory()->for($this->project)->create();
        Occurrence::factory()->for($issue)->create(['occurred_at' => now()->subDays(100)]);
        $channel = $this->project->logChannels()->sole();
        LogEntry::factory()->for($channel, 'channel')->create(['occurred_at' => now()->subDays(20)]);

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(RequestRun::count())->toBe(1)
            ->and(Occurrence::count())->toBe(0)
            ->and(LogEntry::count())->toBe(0)
            ->and($issue->fresh())->not->toBeNull();
    });

    it('prunes web vitals with performance data', function () {
        $row = ['project_id' => $this->project->id, 'route' => '/', 'lcp_us' => 1_000_000];
        DB::table('web_vitals')->insert([[...$row, 'occurred_at' => now()->subDays(20)], [...$row, 'occurred_at' => now()->subDays(2)]]);

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(DB::table('web_vitals')->count())->toBe(1);
    });

    it('keeps a failed job run as long as errors are kept', function () {
        JobRun::factory()->for($this->project)->failed()->create(['created_at' => now()->subDays(40)]);
        JobRun::factory()->for($this->project)->create(['created_at' => now()->subDays(40)]);

        app(PruneProjectTelemetryAction::class)->execute($this->project);

        expect(JobRun::sole()->status->value)->toBe('failed');
    });
});
