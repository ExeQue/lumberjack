<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\ResolveRetentionDaysAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Enums\RetentionLevel;
use App\Domains\Retention\Models\RetentionPolicy;

covers(ResolveRetentionDaysAction::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->team = $this->project->team;
    $this->resolve = app(ResolveRetentionDaysAction::class);
    $this->store = fn (RetentionCategory $c, ?int $days, array $scope = []) => RetentionPolicy::factory()->create([...$scope, 'category' => $c, 'days' => $days]);
});

describe('resolving retention', function () {
    it('uses the built-in default of every data type when nothing is stored', function () {
        foreach (RetentionCategory::forTeams() as $category) {
            $resolved = $this->resolve->execute($category, $this->project);

            expect($resolved->days)->toBe($category->defaultDays())
                ->and($resolved->source)->toBe(RetentionLevel::Default)
                ->and($resolved->capped)->toBeFalse();
        }
    });

    it('lets the project override the team and the team the application, for every data type', function () {
        foreach (RetentionCategory::forTeams() as $category) {
            ($this->store)($category, 50);
            expect($this->resolve->execute($category, $this->project)->days)->toBe(50);

            ($this->store)($category, 40, ['team_id' => $this->team->id]);
            expect($this->resolve->execute($category, $this->project)->days)->toBe(40);

            ($this->store)($category, 20, ['project_id' => $this->project->id]);
            $resolved = $this->resolve->execute($category, $this->project);
            expect($resolved->days)->toBe(20)->and($resolved->source)->toBe(RetentionLevel::Project);
        }
    });

    it('lets a job class or log channel win over the project', function () {
        ($this->store)(RetentionCategory::JobRuns, 10, ['project_id' => $this->project->id]);
        ($this->store)(RetentionCategory::JobRuns, 365, ['project_id' => $this->project->id, 'job_class' => 'App\\Jobs\\Charge']);

        expect($this->resolve->execute(RetentionCategory::JobRuns, $this->project, 'App\\Jobs\\Charge')->days)->toBe(365)
            ->and($this->resolve->execute(RetentionCategory::JobRuns, $this->project, 'App\\Jobs\\Other')->days)->toBe(10);
    });

    it('keeps the audit log at the application level only', function () {
        ($this->store)(RetentionCategory::AuditLog, 30, ['team_id' => $this->team->id]);
        ($this->store)(RetentionCategory::AuditLog, 100);

        expect($this->resolve->execute(RetentionCategory::AuditLog, $this->project)->days)->toBe(100)
            ->and(RetentionCategory::forTeams())->not->toContain(RetentionCategory::AuditLog);
    });
});

describe('caps', function () {
    it('caps a team by the application and a project by the team', function () {
        ($this->store)(RetentionCategory::Requests, 30);
        ($this->store)(RetentionCategory::Requests, 60, ['team_id' => $this->team->id]);
        ($this->store)(RetentionCategory::Queries, 20, ['team_id' => $this->team->id]);
        ($this->store)(RetentionCategory::Queries, 25, ['project_id' => $this->project->id]);

        $team = $this->resolve->execute(RetentionCategory::Requests, $this->team);
        $project = $this->resolve->execute(RetentionCategory::Queries, $this->project);

        expect($team->days)->toBe(30)->and($team->capped)->toBeTrue()->and($team->capLevel)->toBe(RetentionLevel::Application)
            ->and($project->days)->toBe(20)->and($project->capped)->toBeTrue()->and($project->capDays)->toBe(20)->and($project->capLevel)->toBe(RetentionLevel::Team);
    });

    it('caps a job class override by the team, not by the project', function () {
        ($this->store)(RetentionCategory::JobRuns, 60, ['team_id' => $this->team->id]);
        ($this->store)(RetentionCategory::JobRuns, 10, ['project_id' => $this->project->id]);
        ($this->store)(RetentionCategory::JobRuns, 365, ['project_id' => $this->project->id, 'job_class' => 'App\\Jobs\\Charge']);

        expect($this->resolve->execute(RetentionCategory::JobRuns, $this->project, 'App\\Jobs\\Charge')->days)->toBe(60);
    });

    it('clamps without rewriting, so raising the cap restores the stored value', function () {
        $app = ($this->store)(RetentionCategory::Logs, 7);
        ($this->store)(RetentionCategory::Logs, 30, ['project_id' => $this->project->id]);

        expect($this->resolve->execute(RetentionCategory::Logs, $this->project)->days)->toBe(7);

        $app->update(['days' => 90]);

        expect($this->resolve->execute(RetentionCategory::Logs, $this->project)->days)->toBe(30)
            ->and(RetentionPolicy::query()->whereProject($this->project->id)->value('days'))->toBe(30);
    });

    it('sets no cap from a level that stores nothing, so existing longer values keep working', function () {
        ($this->store)(RetentionCategory::Occurrences, 365, ['project_id' => $this->project->id]);

        expect($this->resolve->execute(RetentionCategory::Occurrences, $this->project)->days)->toBe(365);
    });
});

describe('forever', function () {
    it('keeps forever when every level above allows it', function () {
        ($this->store)(RetentionCategory::HealthOutages, null);
        ($this->store)(RetentionCategory::HealthOutages, null, ['team_id' => $this->team->id]);
        ($this->store)(RetentionCategory::HealthOutages, null, ['project_id' => $this->project->id]);

        expect($this->resolve->execute(RetentionCategory::HealthOutages, $this->project)->forever())->toBeTrue();
    });

    it('treats forever as above any cap', function () {
        ($this->store)(RetentionCategory::AlertSends, 120);
        ($this->store)(RetentionCategory::AlertSends, null, ['team_id' => $this->team->id]);

        $resolved = $this->resolve->execute(RetentionCategory::AlertSends, $this->project);

        expect($resolved->days)->toBe(120)
            ->and($this->resolve->execute(RetentionCategory::AlertSends, $this->team)->capped)->toBeTrue();
    });

    it('lets an application forever be the default for teams', function () {
        ($this->store)(RetentionCategory::Spans, null);

        expect($this->resolve->execute(RetentionCategory::Spans, $this->project)->days)->toBeNull();
    });
});
