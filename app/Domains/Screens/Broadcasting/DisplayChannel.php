<?php

declare(strict_types=1);

namespace App\Domains\Screens\Broadcasting;

use App\Domains\Screens\Models\Display;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * display.{uuid}: where a paired TV hears that it shows another screen or theme, or was
 * removed. Only the display itself, by its token, listens.
 */
class DisplayChannel
{
    public static function for(Display $display): PrivateChannel
    {
        return new PrivateChannel(self::name($display));
    }

    public static function name(Display $display): string
    {
        return 'display.'.$display->uuid;
    }

    public function join(mixed $viewer, Display $display): bool
    {
        return $viewer instanceof Display && $viewer->is($display);
    }
}
