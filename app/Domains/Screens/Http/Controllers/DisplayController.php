<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\RemoveDisplayAction;
use App\Domains\Screens\Actions\UpdateDisplayAction;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Http\Requests\UpdateDisplayRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Support\DeviceName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team settings > Displays: the team's paired TVs, what each shows, and whether it is
 * online. Members read; team admins change and remove.
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

        $displays = Display::query()->whereTeam($team)->orderBy('name')->get();
        $screens = $team->screens()->orderBy('name')->get(['id', 'uuid', 'name']);
        $uuids = $screens->pluck('uuid', 'id');
        // A computer sorts by the first name among its windows.
        $groups = $displays->groupBy(fn (Display $d): string => $d->device_hash ?? $d->uuid)->map(fn ($g): string => (string) $g->min('name'));

        return Inertia::render('team/displays', [
            // The windows of one computer together, in window order.
            'displays' => $displays->sortBy(fn (Display $d): string => ($groups[$d->device_hash ?? $d->uuid] ?? '').'|'.$d->slot)->values()->map(fn (Display $d): array => [
                'uuid' => $d->uuid,
                'name' => $d->name,
                'screen' => $d->screen_id === null ? null : $uuids->get($d->screen_id),
                'playlist' => array_values(array_filter(array_map(fn (int $id): ?string => $uuids->get($id), $d->playlist ?? []))),
                'rotate_seconds' => $d->rotate_seconds,
                'hold_on_alert' => $d->hold_on_alert,
                'theme' => $d->theme->value,
                'device' => DeviceName::from($d->user_agent),
                // Which computer it runs on, as an opaque key: windows of one share it.
                'computer' => substr(hash('sha256', 'computer|'.($d->device_hash ?? $d->uuid)), 0, 12),
                'slot' => $d->slot,
                'online' => $d->isOnline(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            ])->all(),
            'screens' => $screens->map(fn ($s): array => ['uuid' => $s->uuid, 'name' => $s->name])->values()->all(),
            'themes' => array_map(fn (DisplayTheme $t): string => $t->value, DisplayTheme::cases()),
            'can_manage' => $user->canAdministerTeam($team),
            'tv' => $presenter->tvInstructions(),
        ]);
    }

    public function update(UpdateDisplayRequest $request, Display $display, UpdateDisplayAction $action): RedirectResponse
    {
        $action->execute($display, $request->settings());

        return back()->with('status', __('screens.displays.saved'));
    }

    public function destroy(Request $request, Display $display, RemoveDisplayAction $action): RedirectResponse
    {
        Gate::authorize('delete', $display);
        $action->execute($display, $request->user());

        return back()->with('status', __('screens.displays.removed'));
    }
}
