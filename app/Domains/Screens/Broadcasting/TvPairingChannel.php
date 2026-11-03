<?php

declare(strict_types=1);

namespace App\Domains\Screens\Broadcasting;

use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvViewer;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * tv-pairing.{uuid}: where a TV showing a code hears that it was confirmed. Named by the
 * pairing's uuid, never its code; only the browser holding the pairing cookie listens.
 */
class TvPairingChannel
{
    public static function for(TvPairing $pairing): PrivateChannel
    {
        return new PrivateChannel(self::name($pairing));
    }

    public static function name(TvPairing $pairing): string
    {
        return 'tv-pairing.'.$pairing->uuid;
    }

    public function join(mixed $viewer, string $uuid): bool
    {
        return $viewer instanceof TvViewer && $viewer->holdsPairing($uuid);
    }
}
