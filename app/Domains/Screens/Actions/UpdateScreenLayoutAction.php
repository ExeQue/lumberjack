<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Exceptions\OverlappingWidgetsException;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenLayout;
use App\Domains\Screens\Support\WidgetSchema;

class UpdateScreenLayoutAction
{
    /**
     * The grid and every widget in its cells. Widgets larger than the grid shrink to fit,
     * widgets without a position flow like before (ScreenLayout), widgets outside the grid
     * are kept but not shown; open kiosks re-render in place.
     *
     * @param  list<array<string, mixed>>  $widgets  validated against WidgetSchema::rules()
     *
     * @throws OverlappingWidgetsException
     */
    public function execute(Screen $screen, int $cols, int $rows, array $widgets): Screen
    {
        $cols = max(Screen::MIN_COLS, min(Screen::MAX_COLS, $cols));
        $rows = max(Screen::MIN_ROWS, min(Screen::MAX_ROWS, $rows));

        $widgets = array_map(
            fn (array $widget): array => WidgetSchema::clean($widget, $cols, $rows),
            ScreenLayout::positioned(array_slice($widgets, 0, WidgetSchema::MAX_WIDGETS), $cols, $rows),
        );

        if (ScreenLayout::overlapping($widgets) !== []) {
            throw OverlappingWidgetsException::on($screen);
        }

        $screen->fill(['cols' => $cols, 'rows' => $rows, 'widgets' => $widgets]);

        if ($screen->isDirty()) {
            $screen->save();
            ScreenChanged::dispatch($screen);
        }

        return $screen;
    }
}
