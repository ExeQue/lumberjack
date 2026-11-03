<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;

class UpdateScreenLayoutAction
{
    /**
     * The grid and every widget in order. Widgets larger than the grid shrink to fit;
     * open kiosks re-render in place.
     *
     * @param  list<array<string, mixed>>  $widgets  validated against WidgetSchema::rules()
     */
    public function execute(Screen $screen, int $cols, int $rows, array $widgets): Screen
    {
        $cols = max(Screen::MIN_COLS, min(Screen::MAX_COLS, $cols));
        $rows = max(Screen::MIN_ROWS, min(Screen::MAX_ROWS, $rows));

        $screen->fill([
            'cols' => $cols,
            'rows' => $rows,
            'widgets' => array_map(fn (array $widget): array => WidgetSchema::clean($widget, $cols, $rows), array_slice($widgets, 0, WidgetSchema::MAX_WIDGETS)),
        ]);

        if ($screen->isDirty()) {
            $screen->save();
            ScreenChanged::dispatch($screen);
        }

        return $screen;
    }
}
