<?php

declare(strict_types=1);

use App\Domains\Activity\Actions\SetActivityLabelAction;
use App\Domains\Activity\Eloquent\Builders\ActivityLabelBuilder;
use App\Domains\Activity\Http\Controllers\ActivityLabelController;
use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityLabel;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(ActivityLabelController::class, ActivityLabel::class, SetActivityLabelAction::class, ActivityLabelBuilder::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->admin = User::factory()->inTeam($this->project->team, TeamRole::Admin)->create();
    $this->rename = fn (array $data, ?User $user = null) => $this->actingAs($user ?? $this->admin)
        ->put(route('activity.labels.update', ['project' => $this->project]), $data);
});

describe('naming activity', function () {
    it('reads a recorded name readably until the team names it', function (string $recorded, string $shown) {
        expect(ActivityLabel::readable($recorded))->toBe($shown);
    })->with([
        'dots and underscores as spaces' => ['user.signed_up', 'User signed up'],
        'a group' => ['billing', 'Billing'],
        'spelled words' => ['api.token-created', 'API token created'],
    ]);

    it('names a group and an activity, and shows the names on the activity page', function () {
        Activity::factory()->forProject($this->project)->named('billing', 'order.placed')->create();

        ($this->rename)(['group' => 'billing', 'label' => 'Payments'])->assertRedirect();
        ($this->rename)(['group' => 'billing', 'name' => 'order.placed', 'label' => ' Order placed '])->assertRedirect();

        $this->get(route('activity.index', ['project' => $this->project]))->assertInertia(fn (Assert $page) => $page
            ->where('labels', ['groups' => ['billing' => 'Payments'], 'activities' => ['billing:order.placed' => 'Order placed']])
            ->where('activities.0.label', 'Order placed')
            ->where('activities.0.group_label', 'Payments')
            ->where('can_rename', true));
    });

    it('renames in place, and an empty name goes back to what the app records', function () {
        ActivityLabel::factory()->forProject($this->project)->forActivity('billing', 'order.placed', 'Old')->create();

        ($this->rename)(['group' => 'billing', 'name' => 'order.placed', 'label' => 'New']);
        expect(ActivityLabel::query()->sole()->label)->toBe('New');

        ($this->rename)(['group' => 'billing', 'name' => 'order.placed', 'label' => '']);
        expect(ActivityLabel::query()->count())->toBe(0);
    });

    it('is closed to a member', function () {
        $member = User::factory()->inTeam($this->project->team, TeamRole::Member)->create();

        ($this->rename)(['group' => 'billing', 'label' => 'Payments'], $member)->assertForbidden();

        $this->get(route('activity.index', ['project' => $this->project]))->assertInertia(fn (Assert $page) => $page->where('can_rename', false));
        expect(ActivityLabel::query()->count())->toBe(0);
    });
});
