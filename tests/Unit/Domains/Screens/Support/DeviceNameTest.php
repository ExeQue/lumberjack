<?php

declare(strict_types=1);

use App\Domains\Screens\Support\DeviceName;

covers(DeviceName::class);

test('a user agent reads as the device and browser', function (?string $userAgent, ?string $expected) {
    expect(DeviceName::from($userAgent))->toBe($expected);
})->with([
    'Fire TV with Silk' => ['Mozilla/5.0 (Linux; Android 9; AFTMM) AppleWebKit/537.36 (KHTML, like Gecko) Silk/112.5.1 like Chrome/112.0.5615.213 Safari/537.36', 'Fire TV · Silk'],
    'Android TV with Chrome' => ['Mozilla/5.0 (Linux; Android 12; BRAVIA 4K VH2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Android TV · Chrome'],
    'Raspberry Pi with Chromium' => ['Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/120.0.0.0 Chrome/120.0.0.0 Safari/537.36', 'Raspberry Pi · Chromium'],
    'Samsung TV' => ['Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/4.0 Chrome/76.0.3809.146 TV Safari/537.36', 'Samsung TV · Samsung Internet'],
    'LG TV' => ['Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.79 Safari/537.36', 'LG TV · Chrome'],
    'Chromecast' => ['Mozilla/5.0 (X11; Linux armv7l) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0 Safari/537.36 CrKey/1.56.500000', 'Chromecast · Chrome'],
    'Mac with Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Mac · Safari'],
    'Windows with Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Windows · Edge'],
    'iPhone with Firefox' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Firefox/120.0', 'iPhone · Firefox'],
    'only a browser' => ['Opera/9.80 OPR/100.0', 'Opera'],
    'nothing known' => ['curl/8.0', null],
    'no user agent' => [null, null],
    'an empty one' => ['', null],
]);
