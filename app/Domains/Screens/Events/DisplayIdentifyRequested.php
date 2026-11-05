<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Models\Display;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone asked one display to show its name over the screen for a moment, to tell it
 * apart on a wall of TVs.
 */
class DisplayIdentifyRequested implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly Display $display)
    {
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [DisplayChannel::for($this->display)];
    }

    public function broadcastAs(): string
    {
        return 'display.identify';
    }

    /** @return array{name: string} */
    public function broadcastWith(): array
    {
        return ['name' => $this->display->fullName()];
    }
}
