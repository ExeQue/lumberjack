<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\ScreenShareChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A share link stopped working (revoked, or its key rotated): its open viewers say so.
 */
class ScreenUnshared implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly string $keyHash,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return ScreenShareChannel::for($this->keyHash);
    }

    public function broadcastAs(): string
    {
        return 'screen.unshared';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
