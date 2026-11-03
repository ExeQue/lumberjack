<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;

class CreateScreenAction
{
    /**
     * A screen from a template (empty by default): dark, the app's time zone, every
     * project of the team over the last 24 hours.
     */
    public function execute(Team $team, string $name, ScreenTemplate $template = ScreenTemplate::Blank): Screen
    {
        [$cols, $rows] = $template->grid();

        return $team->screens()->create([
            'name' => $name,
            'timezone' => config('app.timezone'),
            'cols' => $cols,
            'rows' => $rows,
            'widgets' => $template->widgets(),
        ])->refresh();
    }
}
