<?php

declare(strict_types=1);

namespace App\Domains\Screens\Events;

use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Broadcasting\ScreenShareChannel;
use App\Domains\Screens\Models\Screen;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A screen's layout or settings changed, or it was deleted. Carries no layout: an open
 * kiosk reloads the screen's definition, so it never trusts a payload over the server.
 * Viewers of its share link hear it too.
 */
class ScreenChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly Screen $screen,
        private readonly bool $deleted = false,
    ) {
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        $channels = [ScreenChannel::for($this->screen)];
        $share = ScreenShareChannel::forScreen($this->screen);

        if ($share !== null) {
            $channels[] = $share;
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return $this->deleted ? 'screen.deleted' : 'screen.changed';
    }

    /** @return array{updated_at: string|null} */
    public function broadcastWith(): array
    {
        // No uuid: the share channel is public.
        return ['updated_at' => $this->screen->updated_at?->toIso8601String()];
    }
}
