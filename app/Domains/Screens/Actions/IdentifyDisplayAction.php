<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\DisplayIdentifyRequested;
use App\Domains\Screens\Models\Display;

class IdentifyDisplayAction
{
    /** How long the TV shows its name, in seconds. */
    public const int SECONDS = 10;

    /** The display shows its name over the screen for a few seconds. */
    public function execute(Display $display): void
    {
        broadcast(new DisplayIdentifyRequested($display));
    }
}
