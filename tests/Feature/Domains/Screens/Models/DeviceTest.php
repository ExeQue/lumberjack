<?php

declare(strict_types=1);

use App\Domains\Screens\Eloquent\Builders\DeviceBuilder;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;

covers(Device::class, DeviceBuilder::class);

describe('a device', function () {
    it('is online while any of its windows is, and names the browser it runs', function () {
        $device = Device::factory()->create();
        Display::factory()->offline()->onDevice($device, 1)->create();

        expect($device->load('displays')->isOnline())->toBeFalse()
            ->and($device->browser())->toBe('Fire TV · Silk');

        Display::factory()->onDevice($device, 2)->create();

        expect($device->load('displays')->isOnline())->toBeTrue();
    });

    it('knows the next window to add, none once all nine are paired', function () {
        $device = Device::factory()->create();
        Display::factory()->onDevice($device, 1)->create();
        Display::factory()->onDevice($device, 3)->create();

        expect($device->load('displays')->nextSlot())->toBe(2);

        foreach ([2, 4, 5, 6, 7, 8, 9] as $slot) {
            Display::factory()->onDevice($device, $slot)->create();
        }

        expect($device->load('displays')->nextSlot())->toBeNull();
    });

    it('is found by team or by its cookie hash', function () {
        $a = Device::factory()->withCookie('cookie-a')->create();
        $b = Device::factory()->create();
        Device::factory()->create();

        expect(Device::query()->whereTeam($a->team)->orWhereTeam($b->team_id)->count())->toBe(2)
            ->and(Device::query()->whereDeviceHash(hash('sha256', 'cookie-a'))->orWhereDeviceHash('nope')->sole()->is($a))->toBeTrue()
            ->and($a->getRouteKeyName())->toBe('uuid');
    });
});
