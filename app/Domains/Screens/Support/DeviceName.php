<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

/**
 * "Fire TV · Silk" from a user agent: enough to tell which TV asks to be paired, not a
 * full parser. Null when nothing is recognized.
 */
final class DeviceName
{
    /** First match wins, so TVs come before the systems they run on. @var array<string, string> */
    private const array DEVICES = [
        '/\bAFT[A-Z0-9]+\b|Fire TV/' => 'Fire TV',
        '/CrKey/' => 'Chromecast',
        '/Tizen.*(?:SMART-TV|TV)|SMART-TV.*Tizen/i' => 'Samsung TV',
        '/Web0S|webOS/' => 'LG TV',
        '/Android.*(?:\bTV\b|BRAVIA|GoogleTV)/' => 'Android TV',
        '/Raspbian|X11; Linux (?:armv7l|aarch64)/' => 'Raspberry Pi',
        '/iPad/' => 'iPad',
        '/iPhone/' => 'iPhone',
        '/Android/' => 'Android',
        '/CrOS/' => 'Chromebook',
        '/Macintosh/' => 'Mac',
        '/Windows/' => 'Windows',
        '/Linux/' => 'Linux',
    ];

    /** @var array<string, string> */
    private const array BROWSERS = [
        '/Silk\//' => 'Silk',
        '/SamsungBrowser\//' => 'Samsung Internet',
        '/Edg\//' => 'Edge',
        '/OPR\//' => 'Opera',
        '/Firefox\//' => 'Firefox',
        '/Chromium\//' => 'Chromium',
        '/Chrome\//' => 'Chrome',
        '/Safari\//' => 'Safari',
    ];

    public static function from(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        $parts = array_filter([self::first(self::DEVICES, $userAgent), self::first(self::BROWSERS, $userAgent)]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @param array<string, string> $patterns */
    private static function first(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
