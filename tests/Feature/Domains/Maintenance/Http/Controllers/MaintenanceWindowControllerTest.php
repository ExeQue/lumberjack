<?php

declare(strict_types=1);

use App\Domains\Maintenance\Enums\MaintenanceSource;
use App\Domains\Maintenance\Http\Controllers\MaintenanceWindowController;
use App\Domains\Maintenance\Http\Requests\SaveMaintenanceWindowRequest;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Maintenance\Policies\MaintenanceWindowPolicy;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Carbon\CarbonImmutable;

covers(MaintenanceWindowController::class, SaveMaintenanceWindowRequest::class, MaintenanceWindowPolicy::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();
    $this->member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();
    $this->planned = MaintenanceWindow::factory()->of($this->project)->planned()->create();
});

describe('planning a window', function () {
    it('plans one, changes it and ends it', function () {
        $this->actingAs($this->admin)->post(route('maintenance.store', $this->project), [
            'environment' => 'staging',
            'starts_at' => '2026-11-12T08:00:00+00:00',
            'ends_at' => '2026-11-12T09:30:00+00:00',
            'message' => 'Database upgrade',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $window = MaintenanceWindow::query()->where('environment', 'staging')->sole();
        expect($window->project_id)->toBe($this->project->id)
            ->and($window->source)->toBe(MaintenanceSource::Planned)
            ->and($window->message)->toBe('Database upgrade')
            ->and($window->starts_at->toIso8601String())->toBe('2026-11-12T08:00:00+00:00')
            ->and($window->expected_end_at->toIso8601String())->toBe('2026-11-12T09:30:00+00:00')
            ->and($window->ended_at)->toBeNull();

        $this->actingAs($this->admin)->put(route('maintenance.update', [$this->project, $window]), [
            'environment' => '',
            'starts_at' => '2026-11-11T11:00:00+00:00',
            'ends_at' => '2026-11-11T13:00:00+00:00',
        ])->assertSessionHasNoErrors();

        $window->refresh();
        expect($window->environment)->toBe('')
            ->and($window->message)->toBeNull()
            ->and($window->starts_at->toIso8601String())->toBe('2026-11-11T11:00:00+00:00')
            ->and($window->expected_end_at->toIso8601String())->toBe('2026-11-11T13:00:00+00:00');

        $this->actingAs($this->admin)->post(route('maintenance.end', [$this->project, $window]))->assertSessionHasNoErrors();

        expect($window->refresh()->ended_at?->getTimestamp())->toBe(now()->getTimestamp());
    });

    it('records a window planned wholly in the past as ended, not overrun', function () {
        $this->actingAs($this->admin)->post(route('maintenance.store', $this->project), [
            'environment' => 'staging', 'starts_at' => '2026-11-10T08:00:00+00:00', 'ends_at' => '2026-11-10T09:00:00+00:00',
        ])->assertSessionHasNoErrors();

        expect(MaintenanceWindow::query()->where('environment', 'staging')->sole()->ended_at?->toIso8601String())->toBe('2026-11-10T09:00:00+00:00');
    });

    it('ends a window that has not started where it would have started', function () {
        $this->actingAs($this->admin)->post(route('maintenance.end', [$this->project, $this->planned]))->assertRedirect();

        expect($this->planned->refresh()->ended_at->getTimestamp())->toBe($this->planned->starts_at->getTimestamp());
    });

    it('refuses a window that ends before it starts', function (string $field, array $fields) {
        $this->actingAs($this->admin)->post(route('maintenance.store', $this->project), [
            'environment' => 'production',
            'starts_at' => '2026-11-12T08:00:00+00:00',
            'ends_at' => '2026-11-12T09:00:00+00:00',
            ...$fields,
        ])->assertSessionHasErrors($field);

        expect(MaintenanceWindow::query()->count())->toBe(1);
    })->with([
        'an end before the start' => ['ends_at', ['ends_at' => '2026-11-12T07:00:00+00:00']],
        'an end at the start' => ['ends_at', ['ends_at' => '2026-11-12T08:00:00+00:00']],
        'no start' => ['starts_at', ['starts_at' => '']],
        'a message too long' => ['message', ['message' => str_repeat('x', 501)]],
    ]);
});

describe('who may plan', function () {
    it('turns away a member before telling what is wrong with the form', function () {
        $this->actingAs($this->member)->post(route('maintenance.store', $this->project), [])->assertForbidden();
        $this->actingAs($this->member)->put(route('maintenance.update', [$this->project, $this->planned]), [])->assertForbidden();
    });

    it('turns away a member who does not administer the team', function () {
        $this->actingAs($this->member)->post(route('maintenance.store', $this->project), [
            'starts_at' => '2026-11-12T08:00:00+00:00', 'ends_at' => '2026-11-12T09:00:00+00:00',
        ])->assertForbidden();
        $this->actingAs($this->member)->put(route('maintenance.update', [$this->project, $this->planned]), [
            'starts_at' => '2026-11-12T08:00:00+00:00', 'ends_at' => '2026-11-12T09:00:00+00:00',
        ])->assertForbidden();
        $this->actingAs($this->member)->post(route('maintenance.end', [$this->project, $this->planned]))->assertForbidden();

        expect(MaintenanceWindow::query()->count())->toBe(1)
            ->and($this->planned->refresh()->ended_at)->toBeNull();
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->create())->post(route('maintenance.end', [$this->project, $this->planned]))->assertForbidden();

        expect($this->planned->refresh()->ended_at)->toBeNull();
    });

    it('finds no window of another project under this one', function () {
        $foreign = MaintenanceWindow::factory()->of(Project::factory()->create(['team_id' => $this->project->team_id]))->create();

        $this->actingAs($this->admin)->post(route('maintenance.end', [$this->project, $foreign]))->assertNotFound();

        expect($foreign->refresh()->ended_at)->toBeNull();
    });
});
