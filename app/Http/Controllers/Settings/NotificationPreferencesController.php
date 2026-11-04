<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domains\Notifications\Notifications\WatchingNotification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use NotificationChannels\WebPush\PushSubscription;

class NotificationPreferencesController extends Controller
{
    /**
     * How the user hears about what concerns them, and the browsers they get push in.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'notifications' => $user->notification_preferences->toArray(),
            'push' => [
                'public_key' => WatchingNotification::pushConfigured() ? config('webpush.vapid.public_key') : null,
                'devices' => $user->pushSubscriptions()->latest('id')->get()->map(fn (PushSubscription $s): array => [
                    'label' => $s->getAttribute('user_agent'),
                    'created_at' => $s->created_at?->toIso8601String(),
                    // Lets this browser find its own row; only its owner sees it.
                    'endpoint' => $s->endpoint,
                ])->all(),
            ],
        ]);
    }

    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['notification_preferences' => $request->preferences()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.notifications_updated')]);

        return to_route('notifications.edit');
    }
}
