<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Screens\Exceptions\WidgetNotFoundException;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenLayout;
use App\Domains\Screens\Support\WidgetSchema;
use App\Models\User;

class UpdateScreenWidgetAction
{
    public function __construct(
        private readonly SaveScreenAction $save,
    ) {
    }

    /**
     * One widget's settings, in its place: its id, cells and size stay (those change
     * with the layout, which checks they stay clear of the other widgets).
     *
     * @param  array<string, mixed>  $widget  validated against WidgetSchema::rules()
     *
     * @throws WidgetNotFoundException
     */
    public function execute(Screen $screen, string $id, array $widget, User|McpToken|null $by = null, ?int $revision = null): Screen
    {
        $widgets = ScreenLayout::positioned($screen->widgets, $screen->cols, $screen->rows);
        $index = array_search($id, array_column($widgets, 'id'), true);

        if ($index === false) {
            throw WidgetNotFoundException::on($screen, $id);
        }

        $stored = $widgets[$index];
        $widgets[$index] = WidgetSchema::clean([...$widget, 'id' => $id, 'x' => $stored['x'], 'y' => $stored['y'], 'w' => $stored['w'], 'h' => $stored['h']], $screen->cols, $screen->rows);
        $screen->widgets = $widgets;

        return $this->save->execute($screen, $by, $revision);
    }
}
