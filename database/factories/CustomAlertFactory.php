<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Alerts\Enums\CustomAlertLevel;
use App\Domains\Alerts\Models\CustomAlert;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomAlert>
 */
class CustomAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'topic' => 'default',
            'level' => CustomAlertLevel::Info,
            'title' => 'Deploy finished',
            'content' => ['url' => null, 'fields' => [], 'tables' => [], 'links' => []],
            'occurred_at' => now(),
        ];
    }
}
