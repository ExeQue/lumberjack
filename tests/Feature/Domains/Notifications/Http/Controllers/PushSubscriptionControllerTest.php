<?php

declare(strict_types=1);

use App\Domains\Notifications\Http\Controllers\PushSubscriptionController;
use App\Domains\Notifications\Support\BrowserLabel;
use App\Models\User;
use Database\Factories\PushSubscriptionFactory;
use NotificationChannels\WebPush\PushSubscription;

covers(PushSubscriptionController::class, BrowserLabel::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->subscription = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
    ];
});

describe('subscribing a browser', function () {
    it('stores the browser for the signed-in user, named by its user agent', function () {
        $this->actingAs($this->user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36')
            ->post(route('push-subscriptions.store'), $this->subscription)
            ->assertRedirect(route('notifications.edit'));

        $stored = $this->user->pushSubscriptions()->sole();

        expect($stored->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/abc123')
            ->and($stored->public_key)->toBe($this->subscription['keys']['p256dh'])
            ->and($stored->getAttribute('user_agent'))->toBe('Chrome on Linux');
    });

    it('moves a browser to whoever subscribes it next', function () {
        $before = User::factory()->create();
        PushSubscriptionFactory::new()->forUser($before)->create(['endpoint' => $this->subscription['endpoint']]);

        $this->actingAs($this->user)->post(route('push-subscriptions.store'), $this->subscription);

        expect($before->pushSubscriptions()->count())->toBe(0)
            ->and($this->user->pushSubscriptions()->count())->toBe(1);
    });

    it('refuses what is not a push subscription', function (array $payload) {
        $this->actingAs($this->user)->post(route('push-subscriptions.store'), $payload)->assertSessionHasErrors();

        expect(PushSubscription::query()->count())->toBe(0);
    })->with([
        'no keys' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']],
        'a plain http endpoint' => [['endpoint' => 'http://push.example.test/abc', 'keys' => ['p256dh' => 'abc', 'auth' => 'def']]],
        'a key that is not base64url' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'a b<c>', 'auth' => 'def']]],
    ]);

    it('needs a signed-in user', function () {
        $this->post(route('push-subscriptions.store'), $this->subscription)->assertRedirect(route('login'));

        expect(PushSubscription::query()->count())->toBe(0);
    });
});

describe('unsubscribing a browser', function () {
    it('removes one of the user\'s own browsers', function () {
        $own = PushSubscriptionFactory::new()->forUser($this->user)->create();

        $this->actingAs($this->user)
            ->delete(route('push-subscriptions.destroy'), ['endpoint' => $own->endpoint])
            ->assertRedirect(route('notifications.edit'));

        expect(PushSubscription::query()->count())->toBe(0);
    });

    it('leaves someone else\'s browser alone', function () {
        $theirs = PushSubscriptionFactory::new()->forUser(User::factory()->create())->create();

        $this->actingAs($this->user)->delete(route('push-subscriptions.destroy'), ['endpoint' => $theirs->endpoint]);

        expect($theirs->fresh())->not->toBeNull();
    });

    it('needs the endpoint', function () {
        $this->actingAs($this->user)->delete(route('push-subscriptions.destroy'))->assertSessionHasErrors('endpoint');
    });
});

describe('the notification settings page', function () {
    it('lists the user\'s browsers and the key to subscribe with', function () {
        config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);
        PushSubscriptionFactory::new()->forUser($this->user)->create(['user_agent' => 'Firefox on Linux']);
        PushSubscriptionFactory::new()->forUser(User::factory()->create())->create();

        $this->actingAs($this->user)->get(route('notifications.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('push.public_key', 'public')
                ->has('push.devices', 1)
                ->where('push.devices.0.label', 'Firefox on Linux'));
    });

    it('makes the server\'s keys when none are set yet', function () {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $this->actingAs($this->user)->get(route('notifications.edit'))
            ->assertInertia(fn ($page) => $page->where('push.public_key', fn (?string $key): bool => is_string($key) && strlen($key) > 80));
    });
});
