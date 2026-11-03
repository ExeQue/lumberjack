<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\Screen;

class DuplicateScreenAction
{
    /**
     * A copy with the same grid, aspect, theme, scope and widgets, under a new uuid. Not shared:
     * a share link belongs to the screen it was made for.
     */
    public function execute(Screen $screen): Screen
    {
        $copy = $screen->replicate(['uuid', 'share_key', 'share_key_hash', 'share_titles']);
        $copy->name = mb_substr(__('ui/screens.copy_of', ['name' => $screen->name]), 0, 100);
        $copy->save();

        return $copy;
    }
}
