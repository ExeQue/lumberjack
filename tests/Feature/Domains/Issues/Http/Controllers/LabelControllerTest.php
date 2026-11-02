<?php

declare(strict_types=1);

use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Issues\Http\Controllers\LabelController;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(LabelController::class);

beforeEach(function () {
    $this->issue = Issue::factory()->create();
    $this->project = $this->issue->project;
    $this->admin = User::factory()->inTeam($this->project->team)->create();
    $this->label = Label::factory()->for($this->project)->create(['name' => 'payments']);
    $this->issue->labels()->attach($this->label);
});

describe('managing labels', function () {
    it('renames and recolors a label', function () {
        $this->actingAs($this->admin)
            ->put(route('labels.update', [$this->project, $this->label]), ['name' => 'billing', 'color' => 'green'])
            ->assertSessionHasNoErrors();

        expect($this->label->refresh()->name)->toBe('billing')->and($this->label->color)->toBe(LabelColor::Green);
    });

    it('refuses a name another label of the project has', function () {
        Label::factory()->for($this->project)->create(['name' => 'p1']);

        $this->actingAs($this->admin)
            ->put(route('labels.update', [$this->project, $this->label]), ['name' => 'P1', 'color' => 'red'])
            ->assertSessionHasErrors('label');
    });

    it('deletes a label from the project and its issues', function () {
        $this->actingAs($this->admin)->delete(route('labels.destroy', [$this->project, $this->label]))->assertRedirect();

        expect(Label::count())->toBe(0)->and($this->issue->labels()->count())->toBe(0);
    });

    it('is closed to members who cannot administer the project', function () {
        $member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();

        $this->actingAs($member)->delete(route('labels.destroy', [$this->project, $this->label]))->assertForbidden();
    });
});

describe('labels on the issues list', function () {
    it('shows each issue\'s labels and filters by one', function () {
        Issue::factory()->for($this->project)->create(['title' => 'Unlabelled']);

        $this->actingAs($this->admin)
            ->get(route('issues.index', ['project' => $this->project, 'label' => 'payments']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.labels.0.name', 'payments')
                ->where('labels.0.name', 'payments'));
    });

    it('lets a member set an issue\'s labels', function () {
        $this->actingAs($this->admin)
            ->put(route('issues.labels', [$this->project, $this->issue]), ['labels' => ['payments', 'p1']])
            ->assertSessionHasNoErrors();

        expect($this->issue->labels()->pluck('name')->all())->toBe(['p1', 'payments']);
    });
});
