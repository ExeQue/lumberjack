<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\ScreenChanged;
use App\Domains\Screens\Models\Screen;

class DeleteScreenAction
{
    /**
     * Open kiosks of the screen are told, and show that it is gone.
     */
    public function execute(Screen $screen): void
    {
        $screen->delete();
        ScreenChanged::dispatch($screen, true);
    }
}
