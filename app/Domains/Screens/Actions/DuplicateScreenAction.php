<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\Screen;

class DuplicateScreenAction
{
    /**
     * A copy with the same grid, theme, scope and widgets, under a new uuid.
     */
    public function execute(Screen $screen): Screen
    {
        $copy = $screen->replicate(['uuid']);
        $copy->name = mb_substr(__('ui/screens.copy_of', ['name' => $screen->name]), 0, 100);
        $copy->save();

        return $copy;
    }
}
