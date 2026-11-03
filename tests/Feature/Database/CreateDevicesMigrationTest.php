<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(Device::class, Display::class);

describe('moving displays into devices', function () {
    beforeEach(function () {
        $this->migration = require database_path('migrations/2026_11_10_100000_create_devices.php');
        // Back to the schema of before: displays carrying their browser's cookie and agent.
        $this->migration->down();
        $this->legacy = fn (Team $team, string $name, int $slot, ?string $hash, ?string $seen = null, string $agent = 'Silk/1'): int => DB::table('displays')->insertGetId([
            'uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'name' => $name, 'slot' => $slot, 'device_hash' => $hash, 'theme' => 'default',
            'user_agent' => $agent, 'ip' => '10.0.0.'.$slot, 'last_seen_at' => $seen, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    it('makes one device per team and device cookie, named after its lowest window', function () {
        [$team, $other] = Team::factory()->count(2)->create();
        $pc = str_repeat('a', 64);
        $two = ($this->legacy)($team, 'Ops right', 2, $pc, '2026-11-03 10:00:00', 'Chrome/2');
        $one = ($this->legacy)($team, 'Ops left', 1, $pc, '2026-11-03 09:00:00', 'Chrome/1');
        $same = ($this->legacy)($team, 'Ops left', 3, $pc);
        $elsewhere = ($this->legacy)($other, 'Ops left', 1, $pc);
        $lone = ($this->legacy)($team, 'Kitchen', 1, null);
        $loneToo = ($this->legacy)($team, 'Hall', 1, null);

        $this->migration->up();

        $device = Display::query()->find($one)->device;
        expect($device->only('team_id', 'name', 'device_hash'))->toBe(['team_id' => $team->id, 'name' => 'Ops left', 'device_hash' => $pc])
            ->and($device->last_seen_at->toDateTimeString())->toBe('2026-11-03 10:00:00')
            ->and($device->user_agent)->not->toBeNull()
            ->and($device->displays()->orderBy('slot')->get()->map(fn (Display $d): array => [$d->slot, $d->name])->all())->toBe([[1, null], [2, 'Ops right'], [3, null]])
            ->and(Display::query()->find($two)->device_id)->toBe($device->id)
            ->and(Display::query()->find($same)->device_id)->toBe($device->id)
            ->and(Display::query()->find($elsewhere)->device->team_id)->toBe($other->id)
            ->and(Display::query()->find($lone)->device->name)->toBe('Kitchen')
            ->and(Display::query()->find($loneToo)->device_id)->not->toBe(Display::query()->find($lone)->device_id)
            ->and(Device::query()->count())->toBe(4);
    });

    it('gives the windows their names, browser and cookie back when rolled back', function () {
        $team = Team::factory()->create();
        $one = ($this->legacy)($team, 'Ops left', 1, str_repeat('a', 64));
        $two = ($this->legacy)($team, 'Ops right', 2, str_repeat('a', 64));
        $this->migration->up();

        $this->migration->down();

        expect(DB::table('displays')->orderBy('slot')->get(['id', 'name', 'device_hash'])->map(fn (object $d): array => (array) $d)->all())->toBe([
            ['id' => $one, 'name' => 'Ops left', 'device_hash' => str_repeat('a', 64)],
            ['id' => $two, 'name' => 'Ops right', 'device_hash' => str_repeat('a', 64)],
        ]);
    });
});
