<?php

declare(strict_types=1);

use App\Domains\Administration\Support\SystemProbe;

covers(SystemProbe::class);

it('counts memory the kernel gives back on demand as free', function () {
    $meminfo = "MemTotal:       16000000 kB\nMemFree:          500000 kB\nMemAvailable:   12000000 kB\nCached:         11000000 kB\n";

    expect(SystemProbe::memoryFrom($meminfo))->toBe(['used' => 4_000_000 * 1024, 'total' => 16_000_000 * 1024]);
});

it('has no memory reading where the kernel reports none', function (string $meminfo) {
    expect(SystemProbe::memoryFrom($meminfo))->toBeNull();
})->with([
    'another operating system' => [''],
    'a kernel without MemAvailable' => ["MemTotal:       16000000 kB\nMemFree:          500000 kB\n"],
]);
