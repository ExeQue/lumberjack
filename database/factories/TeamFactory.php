<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Alerts\Actions\CreateTeamAlertRulesAction;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /** Like a team made in the app, with its alert rules. */
    public function configure(): static
    {
        return $this->afterCreating(fn (Team $team) => app(CreateTeamAlertRulesAction::class)->execute($team));
    }
}
