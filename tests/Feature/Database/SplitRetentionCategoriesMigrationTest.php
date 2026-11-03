<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Models\RetentionPolicy;
use Tests\Support\Retention\CreatesRetentionData;

covers(RetentionPolicy::class);

uses(CreatesRetentionData::class);

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_11_03_220000_split_retention_categories.php');
    $this->project = Project::factory()->create();
    $this->teamId = $this->project->team_id;
    $this->migration->down();
});



describe('splitting the old retention categories', function () {
    it('gives every type an old category covered the same days', function () {
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'performance', 'days' => 7]);
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'errors', 'days' => 60]);
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'jobs', 'days' => 45]);
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'logs', 'days' => 5]);
        $this->oldPolicy(['project_id' => $this->project->id, 'category' => 'jobs', 'job_class' => 'App\\Jobs\\Charge', 'days' => 365]);

        $this->migration->up();

        expect($this->policiesOf($this->teamId, null))->toEqual([
            'command_runs' => 45, 'health_results' => 7, 'job_runs' => 45, 'logs' => 5, 'occurrences' => 60,
            'queries' => 7, 'requests' => 7, 'scheduled_task_runs' => 45, 'spans' => 7, 'web_vitals' => 7,
        ])->and(DB::table('retention_policies')->where('job_class', 'App\\Jobs\\Charge')->sole())
            ->category->toBe('job_runs')->days->toBe(365);
    });

    it('carries errors over to outages only when longer than the outages default', function () {
        $this->oldPolicy(['project_id' => $this->project->id, 'category' => 'errors', 'days' => 500]);

        $this->migration->up();

        expect($this->policiesOf(null, $this->project->id))->toEqual(['health_outages' => 500, 'occurrences' => 500]);
    });

    it('turns back into the old categories', function () {
        $this->migration->up();
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'spans', 'days' => 9]);
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'web_vitals', 'days' => 20]);
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'occurrences', 'days' => 30]);
        $this->oldPolicy(['team_id' => $this->teamId, 'category' => 'health_outages', 'days' => null]);
        $this->oldPolicy(['category' => 'requests', 'days' => 3]);

        $this->migration->down();

        expect($this->policiesOf($this->teamId, null))->toEqual(['errors' => 30, 'performance' => 20])
            ->and($this->policiesOf(null, null))->toBe([]);

        $this->migration->up();
    });
});
