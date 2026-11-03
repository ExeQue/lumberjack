<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\TeamDisplaysChannel;
use App\Domains\Screens\Models\Display;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A window reported in: its size and when it was last heard from, for the pages that list
 * the team's windows to update in place.
 */
class DisplayUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly Display $display,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return TeamDisplaysChannel::for($this->display->team);
    }

    public function broadcastAs(): string
    {
        return 'display.updated';
    }

    /**
     * @return array{uuid: string, device: string|null, name: string, viewport: array{w: int, h: int}|null, last_seen_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->display->uuid,
            'device' => $this->display->device?->uuid,
            'name' => $this->display->fullName(),
            'viewport' => $this->display->viewport_width === null ? null : ['w' => $this->display->viewport_width, 'h' => (int) $this->display->viewport_height],
            'last_seen_at' => $this->display->last_seen_at?->toIso8601String(),
        ];
    }
}
