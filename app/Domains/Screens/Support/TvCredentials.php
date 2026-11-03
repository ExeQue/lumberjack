<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * What a TV proves itself with, without a session: the pairing cookie while it shows a
 * code, the display token once paired. Both are httpOnly cookies (encrypted like every
 * cookie), never in a URL; only their sha256 is stored.
 */
final class TvCredentials
{
    public const string DISPLAY_COOKIE = 'lj_display';

    public const string PAIRING_COOKIE = 'lj_tv_pairing';

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

    /** The display whose token the request's cookie carries. */
    public static function display(Request $request): ?Display
    {
        $token = self::cookie($request, self::DISPLAY_COOKIE);

        return $token !== null ? self::displayFor($token) : null;
    }

    /** A cookie's value, when the request carries it as a non empty string. */
    public static function cookie(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function displayFor(string $token): ?Display
    {
        $hash = self::hash($token);
        $display = Display::query()->whereTokenHash($hash)->first();

        // The lookup is by hash; compare again in constant time all the same.
        return $display !== null && hash_equals((string) $display->token_hash, $hash) ? $display : null;
    }

    /** The pairing this browser started, in whatever state it is now. */
    public static function pairing(Request $request): ?TvPairing
    {
        $secret = self::cookie($request, self::PAIRING_COOKIE);

        if ($secret === null) {
            return null;
        }

        $hash = self::hash($secret);
        $pairing = TvPairing::query()->where('secret_hash', $hash)->first();

        return $pairing !== null && hash_equals($pairing->secret_hash, $hash) ? $pairing : null;
    }

    public static function displayCookie(string $token): Cookie
    {
        return cookie(self::DISPLAY_COOKIE, $token, self::DISPLAY_COOKIE_MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public static function pairingCookie(string $secret): Cookie
    {
        return cookie(self::PAIRING_COOKIE, $secret, self::PAIRING_COOKIE_MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public static function forget(string $name): Cookie
    {
        return cookie()->forget($name);
    }
}
