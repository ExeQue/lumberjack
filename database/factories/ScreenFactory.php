<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Health\Models\HealthCheck;
use App\Domains\Projects\Models\Project;
use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenOrientation;
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
            'aspect' => ScreenAspect::Wide,
            'orientation' => ScreenOrientation::Landscape,
            'fit' => ScreenFit::Keep,
            'show_header' => true,
            'show_clock' => true,
            'theme' => ScreenTheme::Dark,
            'light_from' => '07:00',
            'light_to' => '19:00',
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

    /** Showing only these projects by default. */
    public function scopedTo(Project ...$projects): static
    {
        return $this->state(fn () => ['scope' => ['projects' => array_map(fn (Project $p): string => $p->uuid, $projects), 'environment' => 'production', 'period' => '7d']]);
    }

    /**
     * Plus a health widget on row 3 picking these checks, rotating to a value of the first
     * check alone on the first check's project.
     */
    public function withHealthWidget(HealthCheck ...$checks): static
    {
        return $this->state(fn (array $attributes) => ['widgets' => [...$attributes['widgets'], [
            'id' => 'health', 'type' => 'health', 'source' => 'checks', 'x' => 0, 'y' => 3, 'w' => 2, 'h' => 1, 'title' => 'Checks',
            'options' => ['checks' => array_map(fn (HealthCheck $c): string => $c->uuid, $checks), 'only_problems' => true],
            'scope' => [],
            'rotate' => [['source' => 'checks', 'seconds' => 20, 'options' => ['checks' => [$checks[0]->uuid]], 'scope' => ['projects' => [$checks[0]->project->uuid]]]],
        ]]]);
    }
}
