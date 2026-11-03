<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenOrientation;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Models\Screen;

class UpdateScreenAction
{
    /**
     * Name, the display's aspect and orientation and how it fits another, theme and its schedule, time zone and
     * the default scope; open kiosks follow.
     *
     * @param  array{name?: string, aspect?: ScreenAspect, orientation?: ScreenOrientation, fit?: ScreenFit, theme?: ScreenTheme, light_from?: string, light_to?: string, timezone?: string, scope?: array{projects: list<string>, environment: string|null, period: string}}  $settings
     */
    public function execute(Screen $screen, array $settings): Screen
    {
        $screen->fill(array_intersect_key($settings, array_flip(['name', 'aspect', 'orientation', 'fit', 'theme', 'light_from', 'light_to', 'timezone', 'scope'])));

        if ($screen->isDirty()) {
            $screen->save();
            ScreenChanged::dispatch($screen);
        }

        return $screen;
    }
}
