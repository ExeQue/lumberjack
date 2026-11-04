<?php

declare(strict_types=1);

namespace App\Domains\Screens\Http\Controllers;

use App\Domains\Screens\Actions\ClaimTvPairingAction;
use App\Domains\Screens\Actions\ResolveScreenDataAction;
use App\Domains\Screens\Actions\StartTvPairingAction;
use App\Domains\Screens\Actions\TouchDisplayAction;
use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Broadcasting\TvPairingChannel;
use App\Domains\Screens\Http\Requests\RestoreDisplayRequest;
use App\Domains\Screens\Http\Requests\TvHeartbeatRequest;
use App\Domains\Screens\Http\Requests\ViewerDataRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Support\ScreenQr;
use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * /tv, a TV's start page: the screens of the display it was paired as, else a pairing
 * code to confirm from a phone. No session needed; the TV proves itself with httpOnly
 * cookies (see TvCredentials), never with anything in a URL. One device runs several
 * windows as /tv/1 to /tv/9, each a display of its own, living at its own address. /tv is
 * the landing page: it pairs window 1 on a new browser, and on a paired one lists its
 * windows to pick from, or another to add. It never forwards by itself.
 */
class TvController
{
    public function __construct(
        private readonly TouchDisplayAction $touch,
    ) {
    }

    public function show(Request $request, ScreenPresenter $presenter, StartTvPairingAction $start, ?int $slot = null): Response
    {
        $device = TvCredentials::cookie($request, TvCredentials::DEVICE_COOKIE) ?? Str::random(40);
        $windows = $slot === null ? TvCredentials::windows($request) : [];

        if ($windows !== []) {
            $response = $this->chooser($request, $windows);
        } else {
            $slot ??= 1;
            $display = TvCredentials::display($request, $slot);
            $response = $display !== null
                ? $this->display($request, $slot, $display, $presenter)
                : $this->pairing($request, $slot, $device, $start);
        }

        $response->headers->set('Cache-Control', 'no-store');
        // Sent again on every visit: the year starts over.
        $response->headers->setCookie(TvCredentials::deviceCookie($device));

        return $response;
    }

    /**
     * The TV asks every few seconds (and at once when Reverb says so) whether its code was
     * confirmed. Once it was, the answer carries the display token, as a cookie and once
     * in the body for the TV's local storage copy.
     */
    public function claim(Request $request, int $slot, ClaimTvPairingAction $claim): JsonResponse
    {
        $pairing = TvCredentials::pairing($request, $slot);

        if ($pairing === null || $pairing->expires_at->isPast()) {
            return response()->json(['status' => 'expired']);
        }

        if ($pairing->confirmed_at === null) {
            return response()->json(['status' => 'waiting']);
        }

        $token = $claim->execute($pairing);

        if ($token === null) {
            return response()->json(['status' => 'expired']);
        }

        return response()->json(['status' => 'paired', 'token' => $token])
            ->withCookie(TvCredentials::displayCookie($slot, $token))
            ->withCookie(TvCredentials::forget(TvCredentials::pairingCookieName($slot)));
    }

    /** A window that lost its cookie but kept the token in local storage gets the cookie back. */
    public function restore(RestoreDisplayRequest $request, int $slot): JsonResponse
    {
        $token = $request->string('token')->toString();

        if (TvCredentials::displayFor($token) === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        return response()->json(['status' => 'paired'])->withCookie(TvCredentials::displayCookie($slot, $token));
    }

    /** The data of one of the display's screens (?screen=uuid, else its first), read only. */
    public function data(ViewerDataRequest $request, int $slot, ResolveScreenDataAction $action): JsonResponse
    {
        $display = $this->paired($request, $slot);
        $screens = collect($display->screens());
        $screen = $request->filled('screen') ? $screens->firstWhere('uuid', $request->string('screen')->toString()) : $screens->first();
        abort_if($screen === null && $request->filled('screen'), 404);

        return response()->json([
            'data' => (object) ($screen === null ? [] : $action->execute($screen, $request->widget())),
            'at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Every minute: keeps last seen and the cookie current, and says what the display
     * should show, so a TV that missed a broadcast still follows within a minute.
     */
    public function heartbeat(TvHeartbeatRequest $request, int $slot): JsonResponse
    {
        $display = $this->paired($request, $slot, $request->viewport());

        return response()->json(['version' => self::version($display)])
            ->withCookie(TvCredentials::displayCookie($slot, (string) TvCredentials::displayToken($request, $slot)));
    }

    private function pairing(Request $request, int $slot, string $device, StartTvPairingAction $start): Response
    {
        $pairing = TvCredentials::pairing($request, $slot);
        $secret = null;

        if ($pairing === null || ! $pairing->isPending()) {
            ['pairing' => $pairing, 'secret' => $secret] = $start->execute($request->userAgent(), $request->ip(), $slot, TvCredentials::hash($device));
        }

        $pairUrl = route('pair.show', $pairing->code);
        $response = Inertia::render('tv/pair', [
            'slot' => $slot,
            'code' => $pairing->formattedCode(),
            'pair_url' => $pairUrl,
            'pair_base' => route('pair'),
            'tv_url' => self::url($slot),
            'qr' => ScreenQr::svg($pairUrl),
            'expires_in' => max(0, (int) now()->diffInSeconds($pairing->expires_at)),
            'channel' => TvPairingChannel::name($pairing),
            'urls' => self::endpoints($slot),
        ])->toResponse($request);

        if ($secret !== null) {
            $response->headers->setCookie(TvCredentials::pairingCookie($slot, $secret));
        }

        return $response;
    }

    /**
     * /tv on a browser with paired windows: which one this window shows, or another to add.
     *
     * @param  non-empty-array<int, Display>  $windows
     */
    private function chooser(Request $request, array $windows): Response
    {
        $free = array_values(array_diff(range(1, Display::MAX_SLOT), array_keys($windows)))[0] ?? null;
        $first = $windows[array_key_first($windows)];

        return Inertia::render('tv/choose', [
            'device' => $first->device->name,
            'windows' => array_map(fn (int $slot, Display $display): array => [
                'slot' => $slot,
                'label' => $display->label(),
                'screens' => array_map(fn (Screen $screen): string => $screen->name, $display->screens()),
                'url' => self::url($slot),
            ], array_keys($windows), $windows),
            'add' => $free === null ? null : ['slot' => $free, 'url' => self::url($free)],
        ])->toResponse($request);
    }

    private function display(Request $request, int $slot, Display $display, ScreenPresenter $presenter): Response
    {
        $this->touch->execute($display, $slot, $request->userAgent(), $request->ip());
        $screens = $display->screens();

        $response = Inertia::render('tv/show', [
            'slot' => $slot,
            'display' => ['uuid' => $display->uuid, 'name' => $display->fullName(), 'label' => $display->label()],
            'display_channel' => DisplayChannel::name($display),
            'version' => self::version($display),
            'rotate_seconds' => $display->rotate_seconds,
            'hold_on_alert' => $display->hold_on_alert,
            'rotation' => $display->rotation,
            'urls' => self::endpoints($slot),
            // ponytail: each screen carries the whole widget catalog; fine for a few screens.
            'screens' => array_map(fn (Screen $screen): array => $presenter->kiosk($screen, [
                'data_url' => route('tv.data', ['slot' => $slot, 'screen' => $screen->uuid], absolute: false),
                'channel' => ScreenChannel::name($screen),
            ], $display->theme->screenTheme()), $screens),
        ])->toResponse($request);

        $response->headers->setCookie(TvCredentials::displayCookie($slot, (string) TvCredentials::displayToken($request, $slot)));

        return $response;
    }

    /** @param  array{w: int, h: int, dpr: float}|null  $viewport */
    private function paired(Request $request, int $slot, ?array $viewport = null): Display
    {
        $display = TvCredentials::display($request, $slot);
        abort_if($display === null, 401);
        $this->touch->execute($display, $slot, $request->userAgent(), $request->ip(), $viewport);

        return $display;
    }

    /** Where a window lives, /tv/1 to /tv/9: never /tv, the landing page. */
    public static function url(int $slot): string
    {
        return route('tv', $slot);
    }

    /**
     * Where a window asks; relative, as the page asks its own host.
     *
     * @return array{claim: string, restore: string, heartbeat: string}
     */
    private static function endpoints(int $slot): array
    {
        return [
            'claim' => route('tv.claim', $slot, absolute: false),
            'restore' => route('tv.restore', $slot, absolute: false),
            'heartbeat' => route('tv.heartbeat', $slot, absolute: false),
        ];
    }

    /** What the display shows; when it changes, the TV reloads. */
    private static function version(Display $display): string
    {
        return md5(json_encode([$display->screenIds(), $display->theme->value, $display->rotate_seconds, $display->hold_on_alert, $display->rotation], JSON_THROW_ON_ERROR));
    }
}
