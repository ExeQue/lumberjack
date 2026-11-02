<?php

declare(strict_types=1);

namespace App\Domains\Live\Events;

use App\Domains\Live\Enums\ChangeKind;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries no data: the page reloads its own props, so permissions and
 * formatting stay on the server.
 */
class ProjectChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly int $projectId,
        private readonly ChangeKind $kind,
        private readonly ?string $issueUuid = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('project.'.$this->projectId);
    }

    public function broadcastAs(): string
    {
        return $this->issueUuid !== null ? 'issue.updated' : $this->kind->value;
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return ['issue' => $this->issueUuid];
    }
}
