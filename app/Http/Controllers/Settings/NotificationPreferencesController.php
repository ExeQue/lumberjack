<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class NotificationPreferencesController extends Controller
{
    /**
     * How the user hears about watched issues, mentions and assignments.
     */
    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['notification_preferences' => $request->preferences()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.notifications_updated')]);

        return to_route('profile.edit');
    }
}
