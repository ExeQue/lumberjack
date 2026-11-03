<?php

declare(strict_types=1);

use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Http\Controllers\RetentionController;
use App\Domains\Retention\Http\Requests\UpdateRetentionRequest;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Retention\CreatesRetentionData;
use Tests\Support\Teams\CreatesTeamMembers;

covers(RetentionController::class, UpdateRetentionRequest::class);

uses(CreatesTeamMembers::class, CreatesRetentionData::class);

beforeEach(function () {
    ['user' => $this->owner, 'project' => $this->project] = $this->memberWithProject(TeamRole::Owner);
    $this->team = $this->project->team;
    $this->admin = User::factory()->inTeam($this->team, TeamRole::Member)->create(['is_admin' => true]);
});


describe('application level', function () {
    it('is only for instance administrators', function () {
        $this->actingAs($this->owner)->get(route('admin.retention'))->assertForbidden();
        $this->actingAs($this->owner)->put(route('admin.retention.update'), ['days' => ['requests' => 5]])->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.retention'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/retention')
                ->has('retention', count(RetentionCategory::cases()))
                ->where('retention.0.effective.days', RetentionCategory::Occurrences->defaultDays()));
    });

    it('stores days and forever for any data type, the audit log included', function () {
        $this->actingAs($this->admin)->put(route('admin.retention.update'), [
            'days' => ['requests' => 5, 'audit_log' => 30],
            'forever' => ['health_outages'],
        ])->assertSessionHasNoErrors();

        expect($this->storedDays(null, null, RetentionCategory::Requests))->toBe(5)
            ->and($this->storedDays(null, null, RetentionCategory::AuditLog))->toBe(30)
            ->and($this->storedDays(null, null, RetentionCategory::HealthOutages))->toBeNull()
            ->and($this->storedDays(null, null, RetentionCategory::Queries))->toBeFalse();
    });

    it('does not rewrite team and project values when lowered', function () {
        RetentionPolicy::factory()->create(['team_id' => $this->team->id, 'category' => RetentionCategory::Logs, 'days' => 60]);

        $this->actingAs($this->admin)->put(route('admin.retention.update'), ['days' => ['logs' => 10]])->assertSessionHasNoErrors();

        expect($this->storedDays($this->team->id, null, RetentionCategory::Logs))->toBe(60);

        $this->actingAs($this->owner)->get(route('team.retention.show'))
            ->assertInertia(fn (Assert $page) => $page->where('retention.8.category', 'logs')
                ->where('retention.8.effective.days', 10)
                ->where('retention.8.effective.capped', true)
                ->where('retention.8.effective.cap_level', 'application'));
    });
});

describe('team level', function () {
    it('is for team admins and owners', function () {
        ['user' => $member] = $this->memberWithProject(TeamRole::Member);

        $this->actingAs($member)->put(route('team.retention'), ['days' => ['requests' => 5]])->assertForbidden();
        $this->actingAs($this->owner)->put(route('team.retention'), ['days' => ['requests' => 5]])->assertSessionHasNoErrors();

        expect($this->storedDays($this->team->id, null, RetentionCategory::Requests))->toBe(5);
    });

    it('rejects a value above the application cap, naming the cap', function () {
        RetentionPolicy::factory()->create(['category' => RetentionCategory::Requests, 'days' => 30]);

        $this->actingAs($this->owner)->put(route('team.retention'), ['days' => ['requests' => 31]])
            ->assertSessionHasErrors(['days.requests' => __('retention.above_cap', ['days' => 30, 'level' => 'application'])]);
        $this->actingAs($this->owner)->put(route('team.retention'), ['days' => ['requests' => 30]])->assertSessionHasNoErrors();
    });

    it('allows forever only when the application does', function () {
        // Unset at the application: the built-in default is not forever, so neither is the team.
        $this->actingAs($this->owner)->put(route('team.retention'), ['forever' => ['alert_sends']])->assertSessionHasErrors('days.alert_sends');

        RetentionPolicy::factory()->create(['category' => RetentionCategory::AlertSends, 'days' => null]);
        $this->actingAs($this->owner)->put(route('team.retention'), ['forever' => ['alert_sends']])->assertSessionHasNoErrors();
        expect($this->storedDays($this->team->id, null, RetentionCategory::AlertSends))->toBeNull();

        RetentionPolicy::factory()->create(['category' => RetentionCategory::HealthOutages, 'days' => 400]);
        $this->actingAs($this->owner)->put(route('team.retention'), ['forever' => ['health_outages']])->assertSessionHasErrors('days.health_outages');
    });

    it('lists retention on its own page, read only for members', function () {
        ['user' => $member] = $this->memberWithProject(TeamRole::Member);

        $this->actingAs($member)->get(route('team.retention.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('team/retention')
                ->has('retention')
                ->where('retention.0.effective.forever_allowed', false));
    });

    it('cannot set the audit log', function () {
        $this->actingAs($this->owner)->put(route('team.retention'), ['days' => ['audit_log' => 5], 'forever' => ['audit_log']])->assertSessionHasErrors('forever.0');

        expect(RetentionPolicy::query()->where('category', 'audit_log')->exists())->toBeFalse();
    });
});

describe('project level', function () {
    it('needs project update rights', function () {
        ['user' => $member, 'project' => $project] = $this->memberWithProject(TeamRole::Member);

        $this->actingAs($member)->put(route('projects.retention', $project), ['days' => ['requests' => 5]])->assertForbidden();
    });

    it('validates the range', function (mixed $days) {
        $this->actingAs($this->owner)->put(route('projects.retention', $this->project), ['days' => ['requests' => $days]])->assertSessionHasErrors('days.requests');
    })->with([0, 3651, 'many']);

    it('rejects values above the team, also for job classes and channels', function () {
        RetentionPolicy::factory()->create(['team_id' => $this->team->id, 'category' => RetentionCategory::JobRuns, 'days' => 20]);
        RetentionPolicy::factory()->create(['team_id' => $this->team->id, 'category' => RetentionCategory::Logs, 'days' => 20]);
        $channel = $this->project->logChannels()->sole();

        $this->actingAs($this->owner)->put(route('projects.retention', $this->project), [
            'days' => ['job_runs' => 21],
            'job_overrides' => [['job_class' => 'App\\Jobs\\Charge', 'days' => 21]],
            'channels' => [$channel->id => 21],
        ])->assertSessionHasErrors(['days.job_runs', 'job_overrides.0.days', 'channels.'.$channel->id]);

        $this->actingAs($this->owner)->put(route('projects.retention', $this->project), [
            'days' => ['job_runs' => 20],
            'job_overrides' => [['job_class' => 'App\\Jobs\\Charge', 'days' => 20]],
        ])->assertSessionHasNoErrors();

        expect($this->storedDays(null, $this->project->id, RetentionCategory::JobRuns))->toBe(20);
    });

    it('resets to the team default when emptied', function () {
        RetentionPolicy::factory()->create(['project_id' => $this->project->id, 'category' => RetentionCategory::Spans, 'days' => 3]);

        $this->actingAs($this->owner)->put(route('projects.retention', $this->project), ['days' => ['spans' => null]])->assertSessionHasNoErrors();

        expect($this->storedDays(null, $this->project->id, RetentionCategory::Spans))->toBeFalse();
    });

    it('shows the team default and the stats on the settings page', function () {
        RetentionPolicy::factory()->create(['team_id' => $this->team->id, 'category' => RetentionCategory::Occurrences, 'days' => 45]);

        $this->actingAs($this->owner)->get(route('projects.settings', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('retention.0.inherited.days', 45)
                ->where('retention.0.inherited.source', 'team')
                ->missing('retention_stats')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('retention_stats.occurrences.rows', 0)));
    });
});
