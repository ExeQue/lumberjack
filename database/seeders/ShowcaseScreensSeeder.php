<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Screens\Actions\CreateScreenAction;
use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Teams\Models\Team;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;

/**
 * Status screens from the templates for the showcase teams, and a TV showing two of
 * them. A screen or device whose name its team already uses is left alone. No display
 * holds a token, so none of them pairs a browser.
 */
class ShowcaseScreensSeeder extends Seeder
{
    /** Per team: screen name, template, the projects it shows (by slug; none for all). */
    private const array SCREENS = [
        'Local Team' => [
            ['Storefront on call', ScreenTemplate::OnCall, ['storefront']],
            ['Infrastructure', ScreenTemplate::Infrastructure, []],
            ['Fulfillment', ScreenTemplate::Background, ['fulfillment-worker']],
        ],
        'Acme Agency' => [['Acme status', ScreenTemplate::Overview, []]],
        'Northwind Labs' => [['Lobby', ScreenTemplate::Overview, []]],
    ];

    /** Per team: device name and the screens on its windows. */
    private const array DEVICES = [
        'Local Team' => ['Support desk' => ['Storefront on call', 'Fulfillment']],
        'Northwind Labs' => ['Lobby TV' => ['Lobby']],
    ];

    public function __construct(private readonly CreateScreenAction $createScreen)
    {
    }

    public function run(): void
    {
        foreach (self::SCREENS as $teamName => $screens) {
            $team = Team::query()->where('name', $teamName)->first();

            foreach ($team !== null ? $screens : [] as [$name, $template, $slugs]) {
                if ($team->screens()->where('name', $name)->exists()) {
                    continue;
                }

                $this->createScreen->execute($team, $name, $template)->forceFill([
                    'timezone' => 'Europe/Copenhagen',
                    'scope' => ['projects' => $team->projects()->whereIn('slug', $slugs)->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all(), 'environment' => 'production', 'period' => '24h'],
                ])->save();
            }
        }

        foreach (self::DEVICES as $teamName => $devices) {
            $team = Team::query()->where('name', $teamName)->first();

            foreach ($team !== null ? $devices : [] as $name => $screens) {
                if (Device::query()->whereTeam($team)->where('name', $name)->exists()) {
                    continue;
                }

                $device = Device::factory()->for($team)->create([
                    'name' => $name,
                    'device_hash' => hash('sha256', 'showcase-'.$teamName.'-'.$name),
                    'user_agent' => 'Mozilla/5.0 (Linux; Android 12; BRAVIA 4K VH2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'last_seen_at' => Showcase::now()->subSeconds(20),
                ]);

                foreach ($screens as $i => $screenName) {
                    Display::factory()->showing($team->screens()->where('name', $screenName)->sole())->onDevice($device, $i + 1)->create([
                        'token_hash' => null,
                        'last_seen_at' => Showcase::now()->subSeconds(20),
                        'viewport_width' => 1920,
                        'viewport_height' => 1080,
                    ]);
                }
            }
        }
    }
}
