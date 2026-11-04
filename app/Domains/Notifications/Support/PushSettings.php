<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Notifications\WatchingNotification;
use App\Models\User;
use NotificationChannels\WebPush\PushSubscription;

/**
 * What the "Enable on this device" control needs: the server's public key, if browser
 * notifications are set up, and the user's browsers that have them on.
 */
final class PushSettings
{
    /** @return array{public_key: mixed, devices: array<int, array<string, mixed>>} */
    public static function for(User $user): array
    {
        return [
            'public_key' => WatchingNotification::pushConfigured() ? config('webpush.vapid.public_key') : null,
            'devices' => $user->pushSubscriptions()->latest('id')->get()->map(fn (PushSubscription $s): array => [
                'label' => $s->getAttribute('user_agent'),
                'created_at' => $s->created_at?->toIso8601String(),
                // Lets this browser find its own row; only its owner sees it.
                'endpoint' => $s->endpoint,
            ])->all(),
        ];
    }
}
