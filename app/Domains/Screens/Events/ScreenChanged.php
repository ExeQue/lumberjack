<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Models\Screen;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A screen's layout or settings changed, or it was deleted. Carries no layout: an open
 * kiosk reloads the screen's definition, so it never trusts a payload over the server.
 */
class ScreenChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly Screen $screen,
        private readonly bool $deleted = false,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return ScreenChannel::for($this->screen);
    }

    public function broadcastAs(): string
    {
        return $this->deleted ? 'screen.deleted' : 'screen.changed';
    }

    /** @return array{screen: string, updated_at: string|null} */
    public function broadcastWith(): array
    {
        return ['screen' => $this->screen->uuid, 'updated_at' => $this->screen->updated_at?->toIso8601String()];
    }
}
