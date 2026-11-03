<?php

declare(strict_types=1);

namespace App\Domains\Screens\Broadcasting;

use App\Domains\Screens\Models\Screen;
use Illuminate\Broadcasting\Channel;

/**
 * screen-share.{hash}: a public channel for the viewers of a share link, named by the
 * key's sha256 so the name gives nothing away (the data needs the key itself), and it
 * changes with the key: viewers of a rotated or revoked link hear nothing more.
 */
class ScreenShareChannel
{
    public static function for(string $keyHash): Channel
    {
        return new Channel(self::name($keyHash));
    }

    public static function name(string $keyHash): string
    {
        return 'screen-share.'.$keyHash;
    }

    public static function forScreen(Screen $screen): ?Channel
    {
        return $screen->share_key_hash === null ? null : self::for($screen->share_key_hash);
    }
}
