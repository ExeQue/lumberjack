<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells one member, on their own channel, that something happened to an issue they watch.
 */
class WatchedIssueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly string $userUuid,
        private readonly string $projectName,
        private readonly string $headline,
        private readonly string $url,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userUuid);
    }

    public function broadcastAs(): string
    {
        return 'issue.watched';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['project_name' => $this->projectName, 'headline' => mb_substr($this->headline, 0, 300), 'url' => $this->url];
    }
}
