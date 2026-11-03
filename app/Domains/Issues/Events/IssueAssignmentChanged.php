<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Notifications\IssueAssignmentNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells one member, on their own channel, that they were assigned to issues or taken off them.
 */
class IssueAssignmentChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly IssueAssignmentNotification $notification,
        private readonly string $userUuid,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userUuid);
    }

    public function broadcastAs(): string
    {
        return $this->notification->assigned ? 'issue.assigned' : 'issue.unassigned';
    }

    /** @return array<string, string|int|null> */
    public function broadcastWith(): array
    {
        return [
            'project_name' => $this->notification->project()->name,
            'headline' => mb_substr($this->notification->headline(), 0, 300),
            'count' => $this->notification->issues->count(),
            'url' => $this->notification->url(),
        ];
    }
}
