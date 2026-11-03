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

        $widget = fn (string $id, string $type, ?string $source, int $x, int $y, int $w = 1, int $h = 1, array $extra = []): array => [
            'id' => $id, 'type' => $type, 'source' => $source, 'rotate' => [], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
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
                $widget('requests', 'stat', 'requests', 0, 0, extra: ['rotate' => ['users_affected', 'apdex']]),
                $widget('errors', 'stat', 'error_rate', 1, 0),
                $widget('p95', 'stat', 'p95', 2, 0),
                $widget('issues', 'stat', 'open_issues', 3, 0),
                $widget('traffic', 'chart', 'requests_errors', 0, 1, 2, 2),
                $widget('latest', 'list', 'latest_issues', 2, 1, 2, 2),
                $widget('health', 'health', 'checks', 0, 3, 2, 2),
                $widget('queues', 'list', 'queues', 2, 3),
                $widget('hosts', 'list', 'hosts', 3, 3),
                $widget('release', 'stat', 'current_release', 2, 4, extra: ['options' => ['compare' => 'none', 'sparkline' => false]]),
                $widget('memory', 'gauge', 'host_memory', 3, 4),
            ],
        ]);
    }
}
