<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\IdentifyDisplayAction;
use App\Domains\Screens\Actions\RemoveDisplayAction;
use App\Domains\Screens\Actions\RequestReloadAction;
use App\Domains\Screens\Actions\UpdateDisplayAction;
use App\Domains\Screens\Broadcasting\TeamDisplaysChannel;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Exceptions\ReloadTooSoonException;
use App\Domains\Screens\Http\Requests\UpdateDisplayRequest;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Presenters\ScreenPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team settings > Displays: the team's paired devices and their windows, what each window
 * shows, and whether it is online. Members read; team admins change and remove.
 */
class DisplayController
{
    public function index(Request $request, ScreenPresenter $presenter): Response|RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;

        if ($team === null) {
            return redirect()->route('team.show');
        }

        $devices = Device::query()->whereTeam($team)->withDisplays()->orderBy('name')->get();
        $screens = $team->screens()->orderBy('name')->get(['id', 'uuid', 'name']);
        $uuids = $screens->pluck('uuid', 'id');

        return Inertia::render('team/displays', [
            'devices' => $devices->map(fn (Device $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->name,
                'browser' => $device->browser(),
                'online' => $device->isOnline(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                // Where its next window pairs; null once all nine are.
                'next_url' => ($slot = $device->nextSlot()) === null ? null : TvController::url($slot),
                'windows' => $device->displays->map(fn (Display $d): array => [
                    'uuid' => $d->uuid,
                    'slot' => $d->slot,
                    'name' => $d->name,
                    'label' => $d->label(),
                    'screen' => $d->screen_id === null ? null : $uuids->get($d->screen_id),
                    'playlist' => array_values(array_filter(array_map(fn (int $id): ?string => $uuids->get($id), $d->playlist ?? []))),
                    'rotate_seconds' => $d->rotate_seconds,
                    'hold_on_alert' => $d->hold_on_alert,
                    'rotation' => $d->rotation,
                    'theme' => $d->theme->value,
                    'viewport' => $d->viewport_width === null ? null : ['w' => $d->viewport_width, 'h' => (int) $d->viewport_height],
                    'online' => $d->isOnline(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'screens' => $screens->map(fn ($s): array => ['uuid' => $s->uuid, 'name' => $s->name])->values()->all(),
            'themes' => array_map(fn (DisplayTheme $t): string => $t->value, DisplayTheme::cases()),
            'can_manage' => $user->canAdministerTeam($team),
            // Where windows reporting in (size, last seen) say so, to update in place.
            'channel' => TeamDisplaysChannel::name($team),
            'tv' => $presenter->tvInstructions(),
        ]);
    }

    public function update(UpdateDisplayRequest $request, Display $display, UpdateDisplayAction $action): RedirectResponse
    {
        $action->execute($display, $request->settings());

        return back()->with('status', __('screens.displays.saved'));
    }

    /** The TV loads its page afresh; at most once every few seconds. */
    public function reload(Display $display, RequestReloadAction $action): RedirectResponse
    {
        Gate::authorize('update', $display);

        try {
            $action->execute($display);
        } catch (ReloadTooSoonException $e) {
            return back()->with('status', $e->getMessage());
        }

        return back()->with('status', __('screens.reload.sent_display', ['name' => $display->fullName()]));
    }

    /** The TV shows its name for a few seconds, to find it on a wall of them. */
    public function identify(Display $display, IdentifyDisplayAction $action): RedirectResponse
    {
        Gate::authorize('update', $display);
        $action->execute($display);

        return back()->with('status', __('screens.identify.sent', ['name' => $display->fullName(), 'seconds' => IdentifyDisplayAction::SECONDS]));
    }

    public function destroy(Request $request, Display $display, RemoveDisplayAction $action): RedirectResponse
    {
        Gate::authorize('delete', $display);
        $action->execute($display, $request->user());

        return back()->with('status', __('screens.displays.removed'));
    }
}
