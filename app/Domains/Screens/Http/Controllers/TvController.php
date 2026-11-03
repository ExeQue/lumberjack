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
use App\Domains\Screens\Http\Requests\ViewerDataRequest;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Presenters\ScreenPresenter;
use App\Domains\Screens\Support\ScreenQr;
use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * /tv, a TV's start page: the screen of the display it was paired as, else a pairing
 * code to confirm from a phone. No session needed; the TV proves itself with httpOnly
 * cookies (see TvCredentials), never with anything in a URL.
 */
class TvController
{
    public function __construct(
        private readonly TouchDisplayAction $touch,
    ) {
    }

    public function show(Request $request, ScreenPresenter $presenter, StartTvPairingAction $start): Response
    {
        $display = TvCredentials::display($request);

        if ($display !== null) {
            return $this->display($request, $display, $presenter);
        }

        $pairing = TvCredentials::pairing($request);
        $cookie = null;

        if ($pairing === null || ! $pairing->isPending()) {
            ['pairing' => $pairing, 'secret' => $secret] = $start->execute($request->userAgent(), $request->ip());
            $cookie = TvCredentials::pairingCookie($secret);
        }

        $pairUrl = route('pair.show', $pairing->code);
        $response = Inertia::render('tv/pair', [
            'code' => $pairing->formattedCode(),
            'pair_url' => $pairUrl,
            'pair_base' => route('pair'),
            'tv_url' => route('tv'),
            'qr' => ScreenQr::svg($pairUrl),
            'expires_in' => max(0, (int) now()->diffInSeconds($pairing->expires_at)),
            'channel' => TvPairingChannel::name($pairing),
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store');

        if ($cookie !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * The TV asks every few seconds (and at once when Reverb says so) whether its code was
     * confirmed. Once it was, the answer carries the display token, as a cookie and once
     * in the body for the TV's local storage copy.
     */
    public function pairing(Request $request, ClaimTvPairingAction $claim): JsonResponse
    {
        $pairing = TvCredentials::pairing($request);

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
            ->withCookie(TvCredentials::displayCookie($token))
            ->withCookie(TvCredentials::forget(TvCredentials::PAIRING_COOKIE));
    }

    /** A TV that lost its cookie but kept the token in local storage gets the cookie back. */
    public function restore(RestoreDisplayRequest $request): JsonResponse
    {
        $token = $request->string('token')->toString();

        if (TvCredentials::displayFor($token) === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        return response()->json(['status' => 'paired'])->withCookie(TvCredentials::displayCookie($token));
    }

    /** The data of the display's screen, read only. */
    public function data(ViewerDataRequest $request, ResolveScreenDataAction $action): JsonResponse
    {
        $display = $this->paired($request);
        $screen = $display->screen;

        return response()->json([
            'data' => (object) ($screen === null ? [] : $action->execute($screen, $request->widget())),
            'at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Every minute: keeps last seen and the cookie current, and says what the display
     * should show, so a TV that missed a broadcast still follows within a minute.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $display = $this->paired($request);

        return response()->json(['version' => self::version($display)])
            ->withCookie(TvCredentials::displayCookie((string) TvCredentials::cookie($request, TvCredentials::DISPLAY_COOKIE)));
    }

    private function display(Request $request, Display $display, ScreenPresenter $presenter): Response
    {
        $this->touch->execute($display, $request->userAgent(), $request->ip());
        $screen = $display->screen;

        $response = Inertia::render('tv/show', [
            'display' => ['uuid' => $display->uuid, 'name' => $display->name],
            'display_channel' => DisplayChannel::name($display),
            'version' => self::version($display),
            // The kiosk's own props, at the top so its partial reloads find them.
            ...($screen === null ? ['screen' => null] : $presenter->kiosk($screen, [
                'data_url' => route('tv.data', absolute: false),
                'channel' => ScreenChannel::name($screen),
            ], $display->theme->screenTheme())),
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store');

        // Sent again on every visit: the year starts over.
        $response->headers->setCookie(TvCredentials::displayCookie((string) TvCredentials::cookie($request, TvCredentials::DISPLAY_COOKIE)));

        return $response;
    }

    private function paired(Request $request): Display
    {
        $display = TvCredentials::display($request);
        abort_if($display === null, 401);
        $this->touch->execute($display, $request->userAgent(), $request->ip());

        return $display;
    }

    /** What the display shows; when it changes, the TV reloads. */
    private static function version(Display $display): string
    {
        return md5($display->screen_id.'|'.$display->theme->value);
    }
}
