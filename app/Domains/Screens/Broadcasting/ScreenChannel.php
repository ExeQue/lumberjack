<?php

declare(strict_types=1);

namespace App\Domains\Screens\Broadcasting;

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\TvViewer;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * screen.{uuid}: where a screen's open kiosks hear that its layout or settings changed.
 * Team members may listen, and a paired display while it shows this screen (alone or in turn). Share link
 * viewers have no session: they hear it on the public ScreenShareChannel instead.
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

    /** @param  mixed  $viewer  a user, or what the "tv" guard found: a TvViewer */
    public function join(mixed $viewer, Screen $screen): bool
    {
        return $viewer instanceof TvViewer ? $viewer->showsScreen($screen) : $viewer instanceof User && $viewer->can('view', $screen);
    }
}
