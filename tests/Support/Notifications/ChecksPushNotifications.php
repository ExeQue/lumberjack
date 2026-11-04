<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Database\Factories\PushSubscriptionFactory;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use NotificationChannels\WebPush\WebPushChannel;

trait ChecksPushNotifications
{
    /**
     * VAPID keys as `webpush:vapid` makes them, so push counts as set up.
     */
    public function configurePush(): void
    {
        config([
            'webpush.vapid.public_key' => 'BADH8kAiaXZv0K-U04vIYyASsigARwBRd3dOGuT1Vq8ui75kueXrrJ5y-il5vsOmR0JNfsV36WHweHBtHKRajC8',
            'webpush.vapid.private_key' => '4RRuvCKheHccYVnT9A2TZCBmXTpAbjhn3V1S-AezyQo',
            'webpush.vapid.subject' => 'mailto:ops@example.test',
        ]);
    }

    /**
     * A member of the project's team with one browser subscribed.
     *
     * @param  array<string, array<string, bool>>  $preferences  changed from the defaults
     */
    public function subscribedMember(Project $project, array $preferences = [], TeamRole $role = TeamRole::Member): User
    {
        $user = User::factory()->inTeam($project->team, $role)->create();

        if ($preferences !== []) {
            $user->forceFill(['notification_preferences' => NotificationPreferences::fromArray(array_replace_recursive($user->notification_preferences->toArray(), $preferences))])->save();
        }

        PushSubscriptionFactory::new()->forUser($user)->create();

        return $user;
    }

    /**
     * @param  class-string<Notification>  $notification
     */
    public function assertPushed(User $user, string $notification, ?callable $check = null): void
    {
        Notifications::assertSentTo($user, $notification, fn (Notification $n, array $channels): bool => in_array(WebPushChannel::class, $channels, true) && ($check === null || $check($n)));
    }

    /**
     * @param  class-string<Notification>  $notification
     */
    public function assertNotPushed(User $user, string $notification): void
    {
        Notifications::assertNotSentTo($user, $notification, fn (Notification $n, array $channels): bool => in_array(WebPushChannel::class, $channels, true));
    }
}
