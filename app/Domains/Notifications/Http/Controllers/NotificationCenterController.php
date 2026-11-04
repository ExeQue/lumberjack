<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Notifications\Http\Requests\MarkAllNotificationsReadRequest;
use App\Domains\Notifications\Http\Requests\MarkNotificationRequest;
use App\Domains\Notifications\Http\Requests\NotificationIndexRequest;
use App\Domains\Notifications\Presenters\NotificationPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification center: the user's own notifications, every team's. Every query goes
 * through $user->notifications(), so nobody reads or marks someone else's.
 */
class NotificationCenterController extends Controller
{
    public const int PER_PAGE = 30;

    public const int RECENT = 15;

    public function index(NotificationIndexRequest $request): Response
    {
        $user = $this->user($request);
        // The id breaks ties of a second, so the cursor never skips or repeats one.
        $page = $user->notifications()->reorder()->latest()->orderByDesc('id')
            ->when($request->boolean('unread'))->whereNull('read_at')
            ->when($request->kind() !== null)->where('type', $request->kind())
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('notifications/index', [
            'notifications' => (new NotificationPresenter($user))->present($page->items()),
            'next_url' => $page->nextPageUrl(),
            'prev_url' => $page->previousPageUrl(),
            'filters' => ['unread' => $request->boolean('unread'), 'kind' => $request->kind()],
            'kinds' => NotificationPreferences::KINDS,
        ]);
    }

    /**
     * The latest few, for the bell's list.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'notifications' => (new NotificationPresenter($user))->present($user->notifications()->reorder()->latest()->orderByDesc('id')->limit(self::RECENT)->get()),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function update(MarkNotificationRequest $request, string $notification): RedirectResponse|JsonResponse
    {
        $row = $this->user($request)->notifications()->whereKey($notification)->firstOrFail();
        $row->forceFill(['read_at' => $request->boolean('read') ? ($row->read_at ?? now()) : null])->save();

        return $request->wantsJson() ? response()->json(['read' => $row->read_at !== null]) : back();
    }

    public function readAll(MarkAllNotificationsReadRequest $request): RedirectResponse|JsonResponse
    {
        $this->user($request)->unreadNotifications()
            ->when($request->kind() !== null)->where('type', $request->kind())
            ->update(['read_at' => now()]);

        return $request->wantsJson() ? response()->json(['unread' => 0]) : back();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
