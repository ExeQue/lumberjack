<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Actions\ExportScreenAction;
use App\Domains\Screens\Models\SavedScreenTemplate;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedScreenTemplate>
 */
class SavedScreenTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Wall',
            'description' => 'The wall by the coffee machine.',
            'definition' => fn (array $attributes): array => app(ExportScreenAction::class)->execute(Screen::factory()->make(['team_id' => $attributes['team_id']])),
        ];
    }

    /** Saved from this screen, in its team. */
    public function from(Screen $screen): static
    {
        return $this->state(fn () => ['team_id' => $screen->team_id, 'name' => $screen->name, 'definition' => app(ExportScreenAction::class)->execute($screen)]);
    }
}
