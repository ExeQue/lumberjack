<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\TvPairingChannel;
use App\Domains\Screens\Models\TvPairing;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone confirmed a TV's code: the TV fetches its display token. Carries no token; the
 * TV asks for it with its pairing cookie.
 */
class TvPairingConfirmed implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly TvPairing $pairing,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return TvPairingChannel::for($this->pairing);
    }

    public function broadcastAs(): string
    {
        return 'pairing.confirmed';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
