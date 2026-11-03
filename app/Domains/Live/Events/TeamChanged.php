<?php

declare(strict_types=1);

namespace App\Domains\Live\Events;

use App\Domains\Live\Enums\TeamChangeKind;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries no data: the page reloads its own props, so what only team admins may see stays on the server.
 */
class TeamChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        private readonly string $teamUuid,
        private readonly TeamChangeKind $kind,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->teamUuid);
    }

    public function broadcastAs(): string
    {
        return $this->kind->value;
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind->value];
    }
}
