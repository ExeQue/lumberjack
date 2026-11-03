<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Models\Team;

class CreateScreenAction
{
    /**
     * An empty screen with the defaults: 4 x 4 cells, dark, the app's time zone, every
     * project of the team over the last 24 hours.
     */
    public function execute(Team $team, string $name): Screen
    {
        return $team->screens()->create([
            'name' => $name,
            'timezone' => config('app.timezone'),
        ])->refresh();
    }
}
