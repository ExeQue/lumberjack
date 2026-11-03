<?php

declare(strict_types=1);

namespace App\Domains\Screens\Broadcasting;

use App\Domains\Screens\Models\Screen;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * screen.{uuid}: where a screen's open kiosks hear that its layout or settings changed.
 * Team members may listen; a paired display or share key authorizes here too once
 * screens can be shown without a session.
 */
class ScreenChannel
{
    public static function for(Screen $screen): PrivateChannel
    {
        return new PrivateChannel(self::name($screen));
    }

    public static function name(Screen $screen): string
    {
        return 'screen.'.$screen->uuid;
    }

    public function join(User $user, Screen $screen): bool
    {
        return $user->can('view', $screen);
    }
}
