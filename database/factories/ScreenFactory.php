<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Screen>
 */
class ScreenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Ops',
            'cols' => 4,
            'rows' => 4,
            'theme' => ScreenTheme::Dark,
            'timezone' => 'Europe/Copenhagen',
            'scope' => ['projects' => [], 'environment' => null, 'period' => '24h'],
            'widgets' => [
                ['id' => 'requests', 'type' => 'stat', 'source' => 'requests', 'rotate' => [], 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'title' => null, 'options' => [], 'scope' => []],
                ['id' => 'traffic', 'type' => 'chart', 'source' => 'requests_errors', 'rotate' => [], 'x' => 1, 'y' => 0, 'w' => 2, 'h' => 2, 'title' => null, 'options' => [], 'scope' => []],
                ['id' => 'clock', 'type' => 'clock', 'source' => null, 'rotate' => [], 'x' => 3, 'y' => 0, 'w' => 1, 'h' => 1, 'title' => null, 'options' => [], 'scope' => []],
            ],
        ];
    }

    /** Light by day (07:00 to 19:00 in Copenhagen), dark at night. */
    public function scheduled(): static
    {
        return $this->state(fn () => ['theme' => ScreenTheme::Schedule, 'light_from' => '07:00', 'light_to' => '19:00']);
    }
}
