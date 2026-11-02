<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => AlertRuleType::NewIssue,
            'enabled' => true,
            'environments' => null,
        ];
    }

    public function regression(): static
    {
        return $this->state(fn () => ['type' => AlertRuleType::Regression]);
    }
}
