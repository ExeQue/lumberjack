<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\ConfirmTvPairingAction;
use App\Domains\Screens\Http\Requests\ConfirmTvPairingRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\DeviceName;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /pair: confirming the code a TV shows, on a phone or laptop that is signed in. Shows
 * which browser asks and since when, so the right TV gets paired.
 */
class PairController
{
    public function index(Request $request): Response
    {
        return $this->page($request, null, null);
    }

    public function show(Request $request, string $code): Response
    {
        $code = TvPairing::normalizeCode($code);

        return $this->page($request, $code, TvPairing::query()->where('code', $code)->wherePending()->first());
    }

    public function confirm(ConfirmTvPairingRequest $request, string $code, ConfirmTvPairingAction $action): RedirectResponse
    {
        $pairing = TvPairing::query()->where('code', TvPairing::normalizeCode($code))->wherePending()->first();
        $screen = $request->screen();
        $display = $pairing === null ? null : $action->execute($pairing, $request->user(), $screen, $request->string('name')->toString());

        if ($display === null) {
            return back()->withErrors(['code' => $action->reasons[0] ?? __('screens.pairing.expired')]);
        }

        // By its uuid in the address rather than flashed: another tab of this browser (the
        // TV itself, when pairing on one machine) would use up a flash first.
        return redirect()->route('pair', ['paired' => $display->uuid]);
    }

    /** @return array{display: string, screen: string|null}|null */
    private function paired(Request $request, User $user): ?array
    {
        $uuid = $request->query('paired');
        $display = is_string($uuid) && Str::isUuid($uuid) ? Display::query()->with('screen:id,name', 'team')->where('uuid', $uuid)->first() : null;

        return $display === null || ! $user->belongsToTeam($display->team) ? null : ['display' => $display->name, 'screen' => $display->screen?->name];
    }

    private function page(Request $request, ?string $code, ?TvPairing $pairing): Response
    {
        /** @var User $user */
        $user = $request->user();
        $teams = $user->teams->filter(fn (Team $team): bool => $user->canAdministerTeam($team));

        return Inertia::render('pair/show', [
            'code' => $code === null ? null : substr($code, 0, 3).(strlen($code) > 3 ? '-'.substr($code, 3) : ''),
            'pairing' => $pairing === null ? null : [
                'code' => $pairing->formattedCode(),
                'device' => DeviceName::from($pairing->user_agent),
                'created_at' => $pairing->created_at?->toIso8601String(),
                'expires_at' => $pairing->expires_at->toIso8601String(),
            ],
            // The screens of every team the user administers: only admins pair.
            'screens' => Screen::query()->whereIn('team_id', $teams->modelKeys())->orderBy('name')->get(['id', 'uuid', 'name', 'team_id'])
                ->map(fn (Screen $s): array => ['uuid' => $s->uuid, 'name' => $s->name, 'team' => $teams->find($s->team_id)?->name])->values()->all(),
            'paired' => $this->paired($request, $user),
        ]);
    }
}
