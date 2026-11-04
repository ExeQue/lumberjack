<?php

declare(strict_types=1);

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Notifications\Data\PushMessage;
use App\Domains\Notifications\Notifications\Concerns\SendsByPreference;
use App\Domains\Notifications\Notifications\ReleaseNotification;
use App\Models\User;
use Database\Factories\PushSubscriptionFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\Notifications\ChecksPushNotifications;

covers(SendsByPreference::class);

uses(ChecksPushNotifications::class);

describe('a browser the push service no longer knows', function () {
    beforeEach(function () {
        $this->configurePush();
        $this->user = User::factory()->create();
        $this->user->forceFill(['notification_preferences' => NotificationPreferences::fromArray(['releases' => ['toast' => false, 'push' => true]])])->save();
        $this->gone = PushSubscriptionFactory::new()->forUser($this->user)->create();
        $this->kept = PushSubscriptionFactory::new()->forUser($this->user)->create();

        // The push service answers 410 Gone for one browser and 201 for the other.
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification');
        $webPush->shouldReceive('flush')->andReturnUsing(function () {
            foreach ([[$this->gone, 410], [$this->kept, 201]] as [$subscription, $status]) {
                yield new MessageSentReport(new Request('POST', $subscription->endpoint), new Response($status), $status === 201, $status === 201 ? 'OK' : 'Gone');
            }
        });
        $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, app(ReportHandler::class)));
    });

    it('is removed when a notification is sent to it, and the others stay', function () {
        $this->user->notifyNow(new ReleaseNotification(new PushMessage('New release in Shop', 'v2 was first seen just now.', 'https://lumberjack.test/projects/shop/releases', 'release-shop')));

        expect($this->gone->fresh())->toBeNull()
            ->and($this->kept->fresh())->not->toBeNull();
    });
});
