<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Http\Requests\DestroyPushSubscriptionRequest;
use App\Domains\Notifications\Http\Requests\StorePushSubscriptionRequest;
use App\Domains\Notifications\Support\BrowserLabel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PushSubscriptionController extends Controller
{
    /**
     * Turns browser notifications on for the browser the request comes from. A browser
     * has one endpoint: subscribing it again, or as someone else on a shared computer,
     * replaces the old row.
     */
    public function store(StorePushSubscriptionRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->updatePushSubscription($request->string('endpoint')->toString(), $request->string('keys.p256dh')->toString(), $request->string('keys.auth')->toString(), 'aes128gcm')
            ->forceFill(['user_agent' => BrowserLabel::from($request->userAgent())])
            ->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.push_enabled')]);

        return back(fallback: route('notifications.edit'));
    }

    /**
     * Turns them off for one of the user's own browsers; someone else's endpoint is left alone.
     */
    public function destroy(DestroyPushSubscriptionRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($request->string('endpoint')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.push_removed')]);

        return back(fallback: route('notifications.edit'));
    }
}
