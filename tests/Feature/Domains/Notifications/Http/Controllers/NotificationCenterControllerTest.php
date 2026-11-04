<?php

declare(strict_types=1);

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Http\Controllers\NotificationCenterController;
use App\Domains\Notifications\Notifications\HealthCheckNotification;
use App\Domains\Notifications\Notifications\ReleaseNotification;
use App\Domains\Notifications\Presenters\NotificationPresenter;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;

covers(NotificationCenterController::class, NotificationPresenter::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $this->user = User::factory()->inTeam($this->project->team)->create();
    $this->message = fn (string $title): PushMessage => PushMessage::about($this->project, $title, 'v2 was first seen just now.', route('releases.index', $this->project), 'release-'.$title);
});

describe('storing notifications', function () {
    it('keeps a notification in the center when the user wants its kind in app, and not otherwise', function () {
        $this->user->notifyNow(new ReleaseNotification(($this->message)('v2')));

        $this->user->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['releases' => ['toast' => false]])])->save();
        $this->user->notifyNow(new ReleaseNotification(($this->message)('v3')));

        expect($this->user->notifications()->count())->toBe(1)
            ->and($this->user->notifications()->sole()->type)->toBe('releases')
            ->and($this->user->notifications()->sole()->data)->toMatchArray(['title' => 'v2', 'project' => 'Shop', 'team_uuid' => $this->project->team->uuid]);
    });
});

describe('the bell', function () {
    it('shares the unread count on every page and lists the latest', function () {
        $this->user->notifyNow(new ReleaseNotification(($this->message)('v2')));
        $this->travel(1)->minutes();
        $this->user->notifyNow(new HealthCheckNotification(($this->message)('Shop is down')));
        $this->user->notifications()->where('type', 'releases')->update(['read_at' => now()]);

        $this->actingAs($this->user)->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->where('notifications_unread', 1));

        $this->actingAs($this->user)->getJson(route('notifications.recent'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.title', 'Shop is down')
            ->assertJsonPath('notifications.0.read', false)
            ->assertJsonPath('notifications.0.project', 'Shop')
            ->assertJsonPath('notifications.0.team', null);
    });

    it('names the team when the user is in several', function () {
        User::query()->whereKey($this->user->id)->first()?->teams()->attach(Team::factory()->create(), ['role' => 'member']);
        $this->user->notifyNow(new ReleaseNotification(($this->message)('v2')));

        $this->actingAs($this->user->fresh())->getJson(route('notifications.recent'))
            ->assertJsonPath('notifications.0.team', $this->project->team->name);
    });
});

describe('the notification center page', function () {
    beforeEach(function () {
        $this->user->notifyNow(new ReleaseNotification(($this->message)('v2')));
        $this->travel(1)->minutes();
        $this->user->notifyNow(new HealthCheckNotification(($this->message)('Shop is down')));
        $this->user->notifications()->where('type', 'releases')->update(['read_at' => now()]);
        $this->user->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['health' => ['toast' => true]])])->save();
    });

    it('lists everything, newest first', function () {
        $this->actingAs($this->user)->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->component('notifications/index')
                ->has('notifications', 2)
                ->where('notifications.0.kind', 'health')
                ->where('notifications.1.read', true)
                ->where('filters', ['unread' => false, 'kind' => null]));
    });

    it('filters by unread and by kind', function (array $query, array $titles) {
        $this->actingAs($this->user)->get(route('notifications.index', $query))
            ->assertInertia(fn ($page) => $page->where('notifications', fn ($rows): bool => collect($rows)->pluck('title')->all() === $titles));
    })->with([
        'unread only' => [['unread' => 1], ['Shop is down']],
        'one kind' => [['kind' => 'releases'], ['v2']],
        'unread of a kind with none' => [['unread' => 1, 'kind' => 'releases'], []],
    ]);

    it('refuses a kind that does not exist', function () {
        $this->actingAs($this->user)->get(route('notifications.index', ['kind' => 'nope']))->assertSessionHasErrors('kind');
    });

    it('pages by cursor', function () {
        foreach (range(1, NotificationCenterController::PER_PAGE) as $i) {
            $this->user->notifyNow(new ReleaseNotification(($this->message)("v{$i}0")));
        }

        $this->actingAs($this->user)->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->has('notifications', NotificationCenterController::PER_PAGE)->where('next_url', fn (?string $url): bool => $url !== null && str_contains($url, 'cursor=')));
    });
});

describe('marking notifications', function () {
    beforeEach(function () {
        $this->user->notifyNow(new ReleaseNotification(($this->message)('v2')));
        $this->travel(1)->minutes();
        $this->user->notifyNow(new HealthCheckNotification(($this->message)('Shop is down')));
        $this->other = User::factory()->inTeam($this->project->team)->create();
        $this->other->notifyNow(new ReleaseNotification(($this->message)('v2')));
        $this->own = $this->user->notifications()->where('type', 'releases')->sole();
    });

    it('marks one of the user\'s own read and unread again', function () {
        $this->actingAs($this->user)->putJson(route('notifications.mark', $this->own->id), ['read' => true])->assertJson(['read' => true]);
        expect($this->own->fresh()->read_at)->not->toBeNull();

        $this->actingAs($this->user)->putJson(route('notifications.mark', $this->own->id), ['read' => false])->assertJson(['read' => false]);
        expect($this->own->fresh()->read_at)->toBeNull();
    });

    it('cannot touch someone else\'s', function () {
        $theirs = $this->other->notifications()->sole();

        $this->actingAs($this->user)->putJson(route('notifications.mark', $theirs->id), ['read' => true])->assertNotFound();

        expect($theirs->fresh()->read_at)->toBeNull();
    });

    it('needs to know which way', function () {
        $this->actingAs($this->user)->putJson(route('notifications.mark', $this->own->id))->assertUnprocessable();
    });

    it('marks all of the user\'s own read, or all of one kind, and nobody else\'s', function () {
        $this->actingAs($this->user)->postJson(route('notifications.read-all'), ['kind' => 'health'])->assertOk();
        expect($this->user->unreadNotifications()->pluck('type')->all())->toBe(['releases']);

        $this->actingAs($this->user)->postJson(route('notifications.read-all'))->assertOk();

        expect($this->user->unreadNotifications()->count())->toBe(0)
            ->and($this->other->unreadNotifications()->count())->toBe(1);
    });
});
