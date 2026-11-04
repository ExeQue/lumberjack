<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\SavedScreenTemplate;
use App\Domains\Screens\Models\Screen;
use App\Models\User;

class SaveScreenTemplateAction
{
    public function __construct(private readonly ExportScreenAction $export)
    {
    }

    /**
     * A template of the screen's team holding the screen's portable file as it is now;
     * later changes to the screen do not change the template.
     */
    public function execute(Screen $screen, string $name, ?string $description, User $user): SavedScreenTemplate
    {
        return SavedScreenTemplate::query()->create([
            'team_id' => $screen->team_id,
            'name' => $name,
            'description' => $description,
            'definition' => $this->export->execute($screen),
            'created_by' => $user->id,
        ]);
    }
}
