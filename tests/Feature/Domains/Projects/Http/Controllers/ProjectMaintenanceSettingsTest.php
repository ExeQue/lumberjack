<?php

declare(strict_types=1);

use App\Domains\Projects\Actions\UpdateProjectAction;
use App\Domains\Projects\Http\Controllers\ProjectSettingsController;
use App\Domains\Projects\Http\Requests\UpdateProjectRequest;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectSettingsController::class, UpdateProjectRequest::class, UpdateProjectAction::class);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject();
    $this->save = fn (array $fields) => $this->actingAs($this->user)->put(route('projects.settings.update', $this->project), [
        'name' => $this->project->name, 'slug' => $this->project->slug, 'allowed_origins' => [], ...$fields,
    ]);
});

describe('the maintenance settings', function () {
    it('saves how long a reported window is expected to take and how often an overrun repeats', function () {
        ($this->save)(['maintenance_expected_minutes' => 45, 'maintenance_repeat_minutes' => 10])->assertSessionHasNoErrors();

        expect($this->project->refresh())
            ->maintenance_expected_minutes->toBe(45)
            ->maintenance_repeat_minutes->toBe(10);

        $this->actingAs($this->user)->get(route('projects.settings', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.maintenance_expected_minutes', 45)
                ->where('settings.maintenance_repeat_minutes', 10));
    });

    it('keeps both when the form leaves them out', function () {
        ($this->save)([])->assertSessionHasNoErrors();

        expect($this->project->refresh())
            ->maintenance_expected_minutes->toBe(30)
            ->maintenance_repeat_minutes->toBe(30);
    });

    it('refuses values out of bounds', function (string $field, mixed $value) {
        ($this->save)([$field => $value])->assertSessionHasErrors($field);
    })->with([
        'no expected minutes' => ['maintenance_expected_minutes', 0],
        'expected minutes past a week' => ['maintenance_expected_minutes', 10_081],
        'a repeat under 5 minutes' => ['maintenance_repeat_minutes', 4],
        'a repeat past a day' => ['maintenance_repeat_minutes', 1441],
        'a repeat that is not a number' => ['maintenance_repeat_minutes', 'often'],
    ]);

    it('takes the bounds themselves', function () {
        ($this->save)(['maintenance_expected_minutes' => 10_080, 'maintenance_repeat_minutes' => 5])->assertSessionHasNoErrors();
        ($this->save)(['maintenance_expected_minutes' => 1, 'maintenance_repeat_minutes' => 1440])->assertSessionHasNoErrors();

        expect($this->project->refresh())
            ->maintenance_expected_minutes->toBe(1)
            ->maintenance_repeat_minutes->toBe(1440);
    });
});
