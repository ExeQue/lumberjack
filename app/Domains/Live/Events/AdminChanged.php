<?php

declare(strict_types=1);

namespace App\Domains\Live\Events;

use App\Domains\Live\Enums\AdminChangeKind;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something an administration page shows changed. Carries no data, like ProjectChanged.
 */
class AdminChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        private readonly AdminChangeKind $kind,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin');
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
