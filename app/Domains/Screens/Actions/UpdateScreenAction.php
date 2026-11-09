<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenOrientation;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use App\Models\User;

class UpdateScreenAction
{
    public function __construct(
        private readonly SaveScreenAction $save,
    ) {
    }

    /**
     * Name, the display's aspect and orientation and how it fits another, whether the header shows, theme and its schedule, time zone and
     * the default scope; open kiosks follow.
     *
     * @param  array{name?: string, aspect?: ScreenAspect, orientation?: ScreenOrientation, fit?: ScreenFit, show_header?: bool, show_clock?: bool, clock_format?: string, theme?: ScreenTheme, light_from?: string, light_to?: string, timezone?: string|null, scope?: array{projects: list<string>, environment: string|null, period: string}}  $settings
     */
    public function execute(Screen $screen, array $settings, User|McpToken|null $by = null, ?int $revision = null): Screen
    {
        $screen->fill(array_intersect_key($settings, array_flip(['name', 'aspect', 'orientation', 'fit', 'show_header', 'show_clock', 'clock_format', 'theme', 'light_from', 'light_to', 'timezone', 'scope'])));

        return $this->save->execute($screen, $by, $revision);
    }
}
