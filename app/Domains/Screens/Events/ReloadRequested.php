<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Broadcasting\ScreenShareChannel;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone asked everything showing a screen (its kiosks, TVs and share link viewers), every
 * window of a device, or one display, to load the page afresh, as after a deploy. Each waits a moment at random
 * first, so a wall of TVs does not ask at once.
 */
class ReloadRequested implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(private readonly Screen|Device|Display $target)
    {
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        if ($this->target instanceof Display) {
            return [DisplayChannel::for($this->target)];
        }

        if ($this->target instanceof Device) {
            return array_values($this->target->displays()->get()->map(DisplayChannel::for(...))->all());
        }

        $share = ScreenShareChannel::forScreen($this->target);

        return $share === null ? [ScreenChannel::for($this->target)] : [ScreenChannel::for($this->target), $share];
    }

    public function broadcastAs(): string
    {
        return $this->target instanceof Screen ? 'screen.reload' : 'display.reload';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
