<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Models\Display;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Seeder;

/**
 * The Local Team's TVs, the way Displays shows them in the docs: one online, one not
 * seen in days, and a computer driving two screens. None holds a token, so no browser
 * is paired by it. Run on its own (db:seed --class=DemoDisplaySeeder); it replaces the
 * displays it made before.
 */
class DemoDisplaySeeder extends Seeder
{
    private const array DISPLAYS = [
        ['name' => 'Office TV', 'slot' => 1, 'device' => 'office-tv', 'seen' => 0, 'theme' => DisplayTheme::Default, 'agent' => 'Mozilla/5.0 (Linux; Android 9; AFTMM) AppleWebKit/537.36 (KHTML, like Gecko) Silk/112.5.1 like Chrome/112.0.5615.213 Safari/537.36'],
        ['name' => 'Kitchen', 'slot' => 1, 'device' => 'kitchen', 'seen' => 4320, 'theme' => DisplayTheme::Light, 'agent' => 'Mozilla/5.0 (Linux; Android 12; BRAVIA 4K VH2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
        ['name' => 'Ops wall left', 'slot' => 1, 'device' => 'ops-pc', 'seen' => 0, 'theme' => DisplayTheme::Default, 'agent' => 'Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/120.0.0.0 Chrome/120.0.0.0 Safari/537.36'],
        ['name' => 'Ops wall right', 'slot' => 2, 'device' => 'ops-pc', 'seen' => 0, 'theme' => DisplayTheme::Default, 'agent' => 'Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chromium/120.0.0.0 Chrome/120.0.0.0 Safari/537.36'],
    ];

    public function run(): void
    {
        $team = Team::query()->where('name', 'Local Team')->first();
        $screen = $team?->screens()->where('name', 'Ops')->first();

        if ($team === null || $screen === null) {
            return;
        }

        Display::query()->whereTeam($team)->whereIn('name', array_column(self::DISPLAYS, 'name'))->delete();

        foreach (self::DISPLAYS as $display) {
            Display::factory()->showing($screen)->create([
                'name' => $display['name'],
                'slot' => $display['slot'],
                'device_hash' => hash('sha256', 'demo-'.$display['device']),
                'theme' => $display['theme'],
                'user_agent' => $display['agent'],
                'last_seen_at' => now()->subMinutes($display['seen']),
                'token_hash' => null,
            ]);
        }
    }
}
