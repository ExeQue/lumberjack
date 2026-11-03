<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Seeder;

/**
 * The Local Team's "Ops" status screen: a 4 x 5 wall with every widget type, the way
 * the docs show it. Run on its own (db:seed --class=DemoScreenSeeder) to add it to an
 * existing database; it replaces an earlier Ops screen of that team.
 */
class DemoScreenSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::query()->where('name', 'Local Team')->first();

        if ($team === null) {
            return;
        }

        $widget = fn (string $id, string $type, ?string $source, int $w = 1, int $h = 1, array $extra = []): array => [
            'id' => $id, 'type' => $type, 'source' => $source, 'rotate' => [], 'w' => $w, 'h' => $h,
            'title' => null, 'options' => [], 'scope' => [], ...$extra,
        ];

        $team->screens()->where('name', 'Ops')->delete();
        Screen::factory()->for($team)->create([
            'name' => 'Ops',
            'cols' => 4,
            'rows' => 5,
            'theme' => ScreenTheme::Dark,
            'timezone' => 'Europe/Copenhagen',
            'scope' => ['projects' => [], 'environment' => null, 'period' => '24h'],
            'widgets' => [
                $widget('requests', 'stat', 'requests', extra: ['rotate' => ['users_affected', 'apdex']]),
                $widget('errors', 'stat', 'error_rate'),
                $widget('p95', 'stat', 'p95'),
                $widget('issues', 'stat', 'open_issues'),
                $widget('traffic', 'chart', 'requests_errors', 2, 2),
                $widget('latest', 'list', 'latest_issues', 2, 2),
                $widget('health', 'health', 'checks', 2, 2),
                $widget('queues', 'list', 'queues'),
                $widget('hosts', 'list', 'hosts'),
                $widget('release', 'stat', 'current_release', extra: ['options' => ['compare' => 'none', 'sparkline' => false]]),
                $widget('memory', 'gauge', 'host_memory'),
            ],
        ]);
    }
}
