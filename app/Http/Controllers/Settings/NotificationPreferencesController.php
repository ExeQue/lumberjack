<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Notifications\Support\PushSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
            'presets' => NotificationPreferences::presets(),
            'push' => PushSettings::for($user),
        ]);
    }

    /**
     * Saved from the settings page and from the onboarding, so it goes back to where it came from.
     */
    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['notification_preferences' => $request->preferences()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.notifications_updated')]);

        return back(fallback: route('notifications.edit'));
    }
}
