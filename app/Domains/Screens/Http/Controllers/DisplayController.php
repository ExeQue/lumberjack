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

        return Inertia::render('team/displays', [
            'displays' => Display::query()->whereTeam($team)->with('screen:id,uuid')->orderBy('name')->get()->map(fn (Display $d): array => [
                'uuid' => $d->uuid,
                'name' => $d->name,
                'screen' => $d->screen?->uuid,
                'theme' => $d->theme->value,
                'device' => DeviceName::from($d->user_agent),
                'online' => $d->isOnline(),
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            ])->all(),
            'screens' => $team->screens()->orderBy('name')->get(['uuid', 'name'])->map(fn ($s): array => ['uuid' => $s->uuid, 'name' => $s->name])->all(),
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
