<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Events\DisplayChanged;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;

class UpdateDisplayAction
{
    /**
     * Its name, the screen it shows (one of its team's, or none) and its theme; the TV
     * switches at once.
     *
     * @param  array{name?: string, screen_id?: int|null, theme?: DisplayTheme}  $settings
     */
    public function execute(Display $display, array $settings): Display
    {
        $display->fill(array_intersect_key($settings, array_flip(['name', 'screen_id', 'theme'])));

        // Only a screen of its own team.
        if ($display->isDirty('screen_id') && $display->screen_id !== null && Screen::query()->whereKey($display->screen_id)->value('team_id') !== $display->team_id) {
            $display->screen_id = $display->getOriginal('screen_id');
        }

        if ($display->isDirty()) {
            $display->save();
            $display->unsetRelation('screen');
            DisplayChanged::dispatch($display);
        }

        return $display;
    }
}
