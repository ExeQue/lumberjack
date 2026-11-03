<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Exceptions\WidgetNotFoundException;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\WidgetSchema;

class UpdateScreenWidgetAction
{
    /**
     * One widget's settings, in its place; its id stays.
     *
     * @param  array<string, mixed>  $widget  validated against WidgetSchema::rules()
     *
     * @throws WidgetNotFoundException
     */
    public function execute(Screen $screen, string $id, array $widget): Screen
    {
        $widgets = $screen->widgets;
        $index = array_search($id, array_column($widgets, 'id'), true);

        if ($index === false) {
            throw WidgetNotFoundException::on($screen, $id);
        }

        $widgets[$index] = WidgetSchema::clean([...$widget, 'id' => $id], $screen->cols, $screen->rows);
        $screen->widgets = $widgets;

        if ($screen->isDirty()) {
            $screen->save();
            ScreenChanged::dispatch($screen);
        }

        return $screen;
    }
}
