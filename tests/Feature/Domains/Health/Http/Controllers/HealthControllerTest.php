<?php

declare(strict_types=1);

use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Http\Controllers\HealthController;
use App\Domains\Health\Http\Requests\SaveHealthCheckRequest;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthCheckHost;
use App\Domains\Health\Policies\HealthCheckPolicy;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Health\CreatesDatabaseHealthResults;

covers(HealthController::class, SaveHealthCheckRequest::class, HealthCheckPolicy::class);

uses(CreatesDatabaseHealthResults::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->reported = HealthCheck::factory()->for($this->project)->create();
    $this->heartbeat = HealthCheck::factory()->for($this->project)->monitored(HealthCheckType::Heartbeat, [])->create(['name' => 'Backups']);
});

describe('the health page', function () {
    it('passes a database check\'s size and connections with its latest result', function () {
        $this->createDatabaseResult($this->reported, ['latency_ms' => 1.5, 'size_bytes' => 14_470_000, 'connections' => 4, 'max_connections' => 100]);

        $this->actingAs($this->member)
            ->get(route('health.index', ['project' => $this->project, 'check' => $this->reported->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checks.1.metrics', ['latency_ms' => 1.5, 'size_bytes' => 14_470_000, 'connections' => 4, 'max_connections' => 100])
                ->where('checks.1.hosts.0.metrics.size_bytes', 14_470_000)
                ->where('detail.results.0.metrics.connections', 4));
    });

    it('lists the checks with their state, and the selected one in detail', function () {
        $this->actingAs($this->member)
            ->get(route('health.index', ['project' => $this->project, 'check' => $this->reported->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health/index')
                ->has('checks', 2)
                ->where('checks.0.name', 'Backups')
                ->where('checks.0.state', 'pending')
                ->where('checks.1.slug', 'database:pgsql')
                ->where('detail.uuid', $this->reported->uuid)
                ->has('detail.results', 0)
                ->has('detail.latency_series'));
    });

    it('grays the strip of a stale check from its last result on', function () {
        HealthCheck::query()->whereKey($this->reported->id)->update(['created_at' => now()->subDays(2), 'status' => HealthStatus::Ok, 'last_result_at' => now()->subHours(5)]);
        HealthCheckHost::query()->create(['health_check_id' => $this->reported->id, 'host' => 'web-1', 'status' => HealthStatus::Ok, 'interval_s' => 60, 'last_result_at' => now()->subHours(5)]);

        $this->actingAs($this->member)
            ->get(route('health.index', ['project' => $this->project]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checks.1.state', 'stale')
                ->where('checks.1.strip.0.s', 'ok')
                ->where('checks.1.strip', fn ($strip) => collect($strip)->last()['s'] === 'stale'));
    });

    it('shows the ping URL of a heartbeat only to those who administer the project', function () {
        $url = route('health.ping', $this->heartbeat->ping_token);

        $this->actingAs($this->admin)->get(route('health.index', ['project' => $this->project, 'check' => $this->heartbeat->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('detail.ping_url', $url));
        $this->actingAs($this->member)->get(route('health.index', ['project' => $this->project, 'check' => $this->heartbeat->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('detail.ping_url', null));
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->create())->get(route('health.index', $this->project))->assertForbidden();
    });
});

describe('adding and editing checks', function () {
    it('adds an http check that runs on the next minute', function () {
        $this->actingAs($this->admin)->post(route('health.store', $this->project), [
            'type' => 'http', 'name' => 'Website', 'interval_minutes' => 5, 'degrade_after' => 2, 'recover_after' => 5, 'down_after' => 4,
            'config' => ['url' => 'https://example.com/up', 'method' => 'GET', 'expected_status' => 200, 'body_contains' => '', 'ssl' => 'ignored'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $check = HealthCheck::query()->where('name', 'Website')->sole();
        expect($check->config)->toBe(['url' => 'https://example.com/up', 'method' => 'GET', 'expected_status' => 200])
            ->and($check->next_run_at)->toBeNull()
            ->and($check->ping_token)->toBeNull()
            ->and($check->down_after)->toBe(4);
    });

    it('refuses a check beyond the per-project limit', function () {
        config(['health.max_checks_per_project' => 1]);

        $this->actingAs($this->admin)->post(route('health.store', $this->project), [
            'type' => 'http', 'name' => 'One too many', 'interval_minutes' => 5, 'degrade_after' => 2, 'recover_after' => 5, 'down_after' => 4,
            'config' => ['url' => 'https://example.com/up'],
        ])->assertSessionHasErrors(['type' => 'A project can have at most 1 checks that Lumberjack runs.']);
    });

    it('refuses a timeout longer than the most a check waits', function () {
        $this->actingAs($this->admin)->post(route('health.store', $this->project), [
            'type' => 'http', 'name' => 'Slow', 'interval_minutes' => 5, 'degrade_after' => 2, 'recover_after' => 5, 'down_after' => 4,
            'config' => ['url' => 'https://example.com/up', 'timeout_s' => 30],
        ])->assertSessionHasErrors('config.timeout_s');
    });

    it('refuses down_after below degrade_after', function () {
        $this->actingAs($this->admin)->post(route('health.store', $this->project), [
            'type' => 'tcp', 'name' => 'Database', 'interval_minutes' => 5, 'degrade_after' => 3, 'recover_after' => 5, 'down_after' => 2, 'config' => ['host' => 'db.example.com', 'port' => 5432],
        ])->assertSessionHasErrors(['down_after' => 'Down after must be at least degraded after (3); set them the same to go straight to down.']);

        // A reported check compares against the degrade_after it already has.
        $this->reported->update(['degrade_after' => 4]);
        $this->actingAs($this->admin)->put(route('health.update', [$this->project, $this->reported]), ['down_after' => 3])->assertSessionHasErrors('down_after');
        $this->actingAs($this->admin)->put(route('health.update', [$this->project, $this->reported]), ['down_after' => 4])->assertSessionHasNoErrors();

        expect($this->reported->refresh())->down_after->toBe(4)->thresholds_overridden->toBeTrue();
    });

    it('validates the settings of the type', function () {
        $this->actingAs($this->admin)->post(route('health.store', $this->project), [
            'type' => 'tcp', 'name' => 'Database', 'interval_minutes' => 7, 'degrade_after' => 2, 'recover_after' => 5, 'config' => ['host' => 'db.example.com'],
        ])->assertSessionHasErrors(['interval_minutes', 'config.port']);
    });

    it('is refused to a member who cannot administer the project', function () {
        $this->actingAs($this->member)->post(route('health.store', $this->project), [
            'type' => 'dns', 'name' => 'Mail', 'interval_minutes' => 60, 'degrade_after' => 2, 'recover_after' => 5, 'down_after' => 5, 'config' => ['name' => 'example.com', 'record_type' => 'MX'],
        ])->assertForbidden();

        $this->actingAs($this->member)->delete(route('health.destroy', [$this->project, $this->heartbeat]))->assertForbidden();
    });

    it('overrides the thresholds of a reported check, and gives them back to the app', function () {
        $this->actingAs($this->admin)->put(route('health.update', [$this->project, $this->reported]), [
            'name' => 'Primary database', 'degrade_after' => 4, 'recover_after' => 8, 'muted' => true,
        ])->assertSessionHasNoErrors();

        expect($this->reported->refresh())
            ->name->toBe('Primary database')
            ->degrade_after->toBe(4)
            ->thresholds_overridden->toBeTrue()
            ->muted->toBeTrue();

        $this->actingAs($this->admin)->put(route('health.update', [$this->project, $this->reported]), ['degrade_after' => null, 'recover_after' => null, 'down_after' => null]);

        expect($this->reported->refresh()->thresholds_overridden)->toBeFalse();
    });

    it('archives a check and deletes one', function () {
        $this->actingAs($this->admin)->put(route('health.archive', [$this->project, $this->reported]), ['archived' => true])->assertRedirect();
        $this->actingAs($this->admin)->delete(route('health.destroy', [$this->project, $this->heartbeat]))->assertRedirect();

        expect($this->reported->refresh()->archived_at)->not->toBeNull()
            ->and(HealthCheck::count())->toBe(1);
    });

    it('does not reach a check of another project', function () {
        $foreign = HealthCheck::factory()->create();

        $this->actingAs($this->admin)->put(route('health.archive', [$this->project, $foreign]), ['archived' => true])->assertNotFound();
    });
});
