<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Seeder;

/**
 * The Local Team's TVs, the way Displays shows them in the docs: one online, one not
 * seen in days, and a device driving two screens. None holds a token, so no browser
 * is paired by it. Run on its own (db:seed --class=DemoDisplaySeeder); it replaces the
 * devices it made before.
 */
class DemoDisplaySeeder extends Seeder
{
    /** Each device with its windows: window number => screen seen how many minutes ago. */
    private const array DEVICES = [
        ['name' => 'Office TV', 'seen' => [1 => 0], 'theme' => DisplayTheme::Default, 'agent' => 'Mozilla/5.0 (Linux; Android 9; AFTMM) AppleWebKit/537.36 (KHTML, like Gecko) Silk/112.5.1 like Chrome/112.0.5615.213 Safari/537.36'],
        ['name' => 'Kitchen', 'seen' => [1 => 4320], 'theme' => DisplayTheme::Light, 'agent' => 'Mozilla/5.0 (Linux; Android 12; BRAVIA 4K VH2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
        ['name' => 'Ops wall', 'seen' => [1 => 0, 2 => 0], 'theme' => DisplayTheme::Default, 'agent' => 'Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/120.0.0.0 Chrome/120.0.0.0 Safari/537.36'],
    ];

    public function run(): void
    {
        $team = Team::query()->where('name', 'Local Team')->first();
        $screen = $team?->screens()->where('name', 'Ops')->first();

        if ($team === null || $screen === null) {
            return;
        }

        Device::query()->whereTeam($team)->whereIn('name', array_column(self::DEVICES, 'name'))->delete();

        foreach (self::DEVICES as $spec) {
            $device = Device::factory()->for($team)->create([
                'name' => $spec['name'],
                'device_hash' => hash('sha256', 'demo-'.$spec['name']),
                'user_agent' => $spec['agent'],
                'last_seen_at' => now()->subMinutes(min($spec['seen'])),
            ]);

            foreach ($spec['seen'] as $slot => $minutes) {
                Display::factory()->showing($screen)->onDevice($device, $slot)->create([
                    'theme' => $spec['theme'],
                    'last_seen_at' => now()->subMinutes($minutes),
                    'viewport_width' => 1920,
                    'viewport_height' => 1080,
                    'token_hash' => null,
                ]);
            }
        }
    }
}
