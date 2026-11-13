<?php

declare(strict_types=1);

use App\Domains\Activity\Http\Controllers\ActivityController;
use App\Domains\Activity\Models\Activity;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

covers(ActivityController::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-13 10:20:00');
    $this->project = Project::factory()->create();
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->events = Activity::factory()->forProject($this->project);
});

describe('the events page', function () {
    it('lists each event of the period with its count', function () {
        $this->events->named('billing', 'order.placed')->count(3)->create();
        $this->events->named('auth', 'user.signed_in')->create();
        $this->events->named('auth', 'user.signed_in')->create(['occurred_at' => now()->subDays(2)]);

        $this->actingAs($this->user)
            ->get(route('events.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('events/index')
                ->has('events', 2)
                ->where('events.0', fn ($e) => $e['group'] === 'billing' && $e['name'] === 'order.placed' && $e['count'] === 3 && array_sum($e['series']) === 3)
                ->where('events.1.count', 1));
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->get(route('events.index', ['project' => $this->project]))
            ->assertForbidden();
    });
});

describe('one event', function () {
    it('lists its occurrences newest first', function () {
        $this->events->named('billing', 'order.placed')->create(['occurred_at' => now()->subHours(2)]);
        $newest = $this->events->named('billing', 'order.placed')->create(['occurred_at' => now()->subMinute()]);
        $this->events->named('billing', 'order.refunded')->create();

        $this->actingAs($this->user)
            ->get(route('events.show', ['project' => $this->project, 'group' => 'billing', 'name' => 'order.placed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('events/show')
                ->has('occurrences', 2)
                ->where('occurrences.0.id', $newest->id)
                ->where('stats.count', 2));
    });

    it('sorts by actor, and narrows to one actor or subject', function () {
        $event = $this->events->named('billing', 'order.placed');
        $event->byActor('user', 2)->onSubject('order', 1)->create();
        $event->byActor('user', 1)->onSubject('order', 2)->create();
        $event->byActor('user', 1)->onSubject('order', 1)->create();

        $show = fn (array $query) => $this->actingAs($this->user)
            ->get(route('events.show', ['project' => $this->project, 'group' => 'billing', 'name' => 'order.placed', ...$query]));

        $show(['sort' => 'actor'])->assertInertia(fn (Assert $page) => $page
            ->where('occurrences.0.actor.id', '1')
            ->where('occurrences.1.actor.id', '1')
            ->where('occurrences.2.actor.id', '2'));
        $show(['actor_type' => 'user', 'actor_id' => '1'])->assertInertia(fn (Assert $page) => $page->has('occurrences', 2));
        $show(['subject_type' => 'order', 'subject_id' => '1'])->assertInertia(fn (Assert $page) => $page->has('occurrences', 2));
    });

    it('rejects an unknown sort', function () {
        $this->actingAs($this->user)
            ->get(route('events.show', ['project' => $this->project, 'group' => 'billing', 'name' => 'order.placed', 'sort' => 'payload']))
            ->assertSessionHasErrors('sort');
    });
});
