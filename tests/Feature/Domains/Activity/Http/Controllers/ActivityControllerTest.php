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

describe('the activity page', function () {
    it('lists each event of the period with its count', function () {
        $this->events->named('billing', 'order.placed')->count(3)->create();
        $this->events->named('auth', 'user.signed_in')->create();
        $this->events->named('auth', 'user.signed_in')->create(['occurred_at' => now()->subDays(2)]);

        $this->actingAs($this->user)
            ->get(route('activity.index', ['project' => $this->project]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('activity/index')
                ->has('activities', 2)
                ->where('activities.0', fn ($e) => $e['group'] === 'billing' && $e['name'] === 'order.placed' && $e['count'] === 3 && array_sum($e['series']) === 3)
                ->where('activities.1.count', 1));
    });

    it('counts only the picked actors\' or subjects\' activity', function () {
        $this->events->named('gallery', 'image.uploaded')->byActor('user', 1)->onSubject('image', 10)->onSubject('gallery', 5)->count(2)->create();
        $this->events->named('gallery', 'image.uploaded')->byActor('user', 2)->onSubject('image', 11)->onSubject('gallery', 6)->create();
        $this->events->named('auth', 'user.signed_in')->byActor('user', 2)->create();

        $index = fn (array $query) => $this->actingAs($this->user)->get(route('activity.index', ['project' => $this->project, ...$query]));

        $index(['actors' => ['user:2']])->assertInertia(fn (Assert $page) => $page
            ->has('activities', 2)
            ->where('filters.actors', ['user:2']));
        $index(['subjects' => ['gallery:5']])->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.count', 2));
        $index(['subjects' => ['gallery:5', 'gallery:6']])->assertInertia(fn (Assert $page) => $page
            ->where('activities.0.count', 3));
        $index(['actors' => ['user:1', 'user:2'], 'subjects' => ['image:11']])->assertInertia(fn (Assert $page) => $page
            ->has('activities', 1)
            ->where('activities.0.count', 1));
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->get(route('activity.index', ['project' => $this->project]))
            ->assertForbidden();
    });
});

describe('one activity', function () {
    it('lists its occurrences newest first', function () {
        $this->events->named('billing', 'order.placed')->create(['occurred_at' => now()->subHours(2)]);
        $newest = $this->events->named('billing', 'order.placed')->create(['occurred_at' => now()->subMinute()]);
        $this->events->named('billing', 'order.refunded')->create();

        $this->actingAs($this->user)
            ->get(route('activity.show', ['project' => $this->project, 'group' => 'billing', 'name' => 'order.placed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('activity/show')
                ->has('occurrences', 2)
                ->where('occurrences.0.id', $newest->id)
                ->where('stats.count', 2));
    });

    it('sorts by actor naturally, and narrows to the picked actors or subjects', function () {
        $event = $this->events->named('billing', 'order.placed');
        $event->byActor('user', 10)->onSubject('order', 1)->create();
        $event->byActor('user', 2)->onSubject('order', 2)->create();
        $event->byActor('user', 2)->onSubject('cart', 9)->onSubject('order', 1)->create();

        $show = fn (array $query) => $this->actingAs($this->user)
            ->get(route('activity.show', ['project' => $this->project, 'group' => 'billing', 'name' => 'order.placed', ...$query]));

        $show(['sort' => 'actor'])->assertInertia(fn (Assert $page) => $page
            ->where('occurrences.0.actor.id', '2')
            ->where('occurrences.1.actor.id', '2')
            ->where('occurrences.2.actor.id', '10'));
        $show(['actors' => ['user:2']])->assertInertia(fn (Assert $page) => $page->has('occurrences', 2));
        $show(['actors' => ['user:2', 'user:10']])->assertInertia(fn (Assert $page) => $page->has('occurrences', 3));
        $show(['subjects' => ['order:1']])->assertInertia(fn (Assert $page) => $page
            ->has('occurrences', 2)
            ->where('occurrences.0.subjects', [['type' => 'cart', 'id' => '9'], ['type' => 'order', 'id' => '1']])
            ->where('stats.count', 2));
    });

    it('rejects an unknown sort and a malformed actor or subject', function (array $query, string $field) {
        $this->actingAs($this->user)
            ->get(route('activity.show', ['project' => $this->project, 'group' => 'billing', 'name' => 'order.placed', ...$query]))
            ->assertSessionHasErrors($field);
    })->with([
        'a sort' => [['sort' => 'payload'], 'sort'],
        'an actor without a key' => [['actors' => ['user']], 'actors.0'],
        'too many subjects' => [['subjects' => array_map(fn (int $i): string => "tag:{$i}", range(1, 21))], 'subjects'],
    ]);
});

describe('filter suggestions', function () {
    beforeEach(function () {
        $event = $this->events->named('gallery', 'image.uploaded');
        $event->byActor('user', 17)->onSubject('gallery', 5)->count(3)->create();
        $event->byActor('user', 18)->onSubject('gallery', 51)->create();
        $event->byActor('team', 1)->onSubject('image', 5)->create();
        $this->references = fn (array $query) => $this->actingAs($this->user)
            ->getJson(route('activity.references', ['project' => $this->project, ...$query]));
    });

    it('suggests the actors or subjects that match what is typed, most active first', function (array $query, array $expected) {
        ($this->references)($query)->assertOk()->assertExactJson($expected);
    })->with([
        'actors of a type' => [['kind' => 'actor', 'q' => 'user'], ['user:17', 'user:18']],
        'an actor by type and key' => [['kind' => 'actor', 'q' => 'user:18'], ['user:18']],
        'subjects whose key starts with it' => [['kind' => 'subject', 'q' => '5'], ['gallery:5', 'gallery:51', 'image:5']],
        'subjects by type and key' => [['kind' => 'subject', 'q' => 'gallery:5'], ['gallery:5', 'gallery:51']],
        'no wildcards from what is typed' => [['kind' => 'actor', 'q' => 'u%'], []],
    ]);

    it('suggests from one activity only when asked', function () {
        $this->events->named('auth', 'user.signed_in')->byActor('user', 99)->create();

        ($this->references)(['kind' => 'actor', 'q' => 'user', 'group' => 'gallery', 'name' => 'image.uploaded'])
            ->assertExactJson(['user:17', 'user:18']);
    });

    it('is closed to someone outside the team', function () {
        $this->actingAs(User::factory()->inTeam()->create())
            ->getJson(route('activity.references', ['project' => $this->project, 'kind' => 'actor']))
            ->assertForbidden();
    });
});
