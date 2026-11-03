<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Models\Display;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A display shows another screen or theme (the TV reloads), or was removed (the TV goes
 * back to showing a pairing code).
 */
class DisplayChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly Display $display,
        private readonly bool $removed = false,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return DisplayChannel::for($this->display);
    }

    public function broadcastAs(): string
    {
        return $this->removed ? 'display.removed' : 'display.changed';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
