<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * What a TV proves itself with, without a session: per window (slot, /tv/{slot}) the
 * pairing cookie while it shows a code and the display token once paired, plus one device
 * cookie that tells the windows of one computer apart from other computers. All are
 * httpOnly cookies (encrypted like every cookie), never in a URL; only their sha256 is
 * stored.
 */
final class TvCredentials
{
    public const string DISPLAY_COOKIE = 'lj_display_';

    public const string PAIRING_COOKIE = 'lj_tv_pairing_';

    public const string DEVICE_COOKIE = 'lj_tv_device';

    /** A year; sent again on every visit and heartbeat, so it rolls. */
    public const int DISPLAY_COOKIE_MINUTES = 525_600;

    public const int PAIRING_COOKIE_MINUTES = 15;

    public static function newDisplayToken(): string
    {
        return 'ljd_'.Str::random(48);
    }

    public static function newSecret(): string
    {
        return Str::random(48);
    }

    /** Six characters of TvPairing::CODE_ALPHABET: 32^6, about a billion. */
    public static function newCode(): string
    {
        $alphabet = TvPairing::CODE_ALPHABET;
        $code = '';

        for ($i = 0; $i < TvPairing::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** @return non-empty-string */
    public static function displayCookieName(int $slot): string
    {
        return self::DISPLAY_COOKIE.$slot;
    }

    /** @return non-empty-string */
    public static function pairingCookieName(int $slot): string
    {
        return self::PAIRING_COOKIE.$slot;
    }

    /** The display token of a window, as its cookie carries it. */
    public static function displayToken(Request $request, int $slot): ?string
    {
        return self::cookie($request, self::displayCookieName($slot));
    }

    /** The display of a window, by the token its cookie carries. */
    public static function display(Request $request, int $slot): ?Display
    {
        $token = self::displayToken($request, $slot);

        return $token !== null ? self::displayFor($token) : null;
    }

    /**
     * The paired windows of this browser, by the tokens their cookies carry: window number
     * to display, in window order.
     *
     * @return array<int, Display>
     */
    public static function windows(Request $request): array
    {
        $hashes = [];

        foreach (range(1, Display::MAX_SLOT) as $slot) {
            $token = self::displayToken($request, $slot);

            if ($token !== null) {
                $hashes[$slot] = self::hash($token);
            }
        }

        if ($hashes === []) {
            return [];
        }

        $displays = Display::query()->whereIn('token_hash', array_values($hashes))->with('device')->get()->keyBy('token_hash');

        return array_filter(array_map(fn (string $hash): ?Display => $displays->get($hash), $hashes));
    }

    public static function displayFor(string $token): ?Display
    {
        $hash = self::hash($token);
        $display = Display::query()->whereTokenHash($hash)->first();

        // The lookup is by hash; compare again in constant time all the same.
        return $display !== null && hash_equals((string) $display->token_hash, $hash) ? $display : null;
    }

    /** The pairing a window started, in whatever state it is now. */
    public static function pairing(Request $request, int $slot): ?TvPairing
    {
        $secret = self::cookie($request, self::pairingCookieName($slot));

        if ($secret === null) {
            return null;
        }

        $hash = self::hash($secret);
        $pairing = TvPairing::query()->where('secret_hash', $hash)->first();

        return $pairing !== null && hash_equals($pairing->secret_hash, $hash) ? $pairing : null;
    }

    /**
     * Every display and pairing the browser's cookies prove, over all its windows: whom
     * a broadcast channel asks about, as it does not know which window asks.
     */
    public static function viewer(Request $request): ?TvViewer
    {
        $tokens = [];
        $secrets = [];

        foreach (range(1, Display::MAX_SLOT) as $slot) {
            $tokens[] = self::displayToken($request, $slot);
            $secrets[] = self::cookie($request, self::pairingCookieName($slot));
        }

        $tokens = array_map(self::hash(...), array_filter($tokens));
        $secrets = array_map(self::hash(...), array_filter($secrets));
        $viewer = new TvViewer(
            $tokens === [] ? collect() : Display::query()->whereIn('token_hash', $tokens)->get(),
            $secrets === [] ? collect() : TvPairing::query()->whereIn('secret_hash', $secrets)->get(),
        );

        return $viewer->isEmpty() ? null : $viewer;
    }

    /** The computer's device cookie, hashed as it is stored. */
    public static function deviceHash(Request $request): ?string
    {
        $device = self::cookie($request, self::DEVICE_COOKIE);

        return $device === null ? null : self::hash($device);
    }

    /** A cookie's value, when the request carries it as a non empty string. */
    public static function cookie(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function displayCookie(int $slot, string $token): Cookie
    {
        return cookie(self::displayCookieName($slot), $token, self::DISPLAY_COOKIE_MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public static function pairingCookie(int $slot, string $secret): Cookie
    {
        return cookie(self::pairingCookieName($slot), $secret, self::PAIRING_COOKIE_MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public static function deviceCookie(string $device): Cookie
    {
        return cookie(self::DEVICE_COOKIE, $device, self::DISPLAY_COOKIE_MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public static function forget(string $name): Cookie
    {
        return cookie()->forget($name);
    }
}
