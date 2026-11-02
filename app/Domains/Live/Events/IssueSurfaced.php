<?php

declare(strict_types=1);

namespace App\Domains\Live\Events;

use App\Domains\Issues\Models\Issue;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new or regressed issue, told to everyone on the team who has Lumberjack
 * open, so the browser can show a toast and play a sound.
 */
class IssueSurfaced implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly Issue $issue,
        private readonly string $kind = 'new',
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('team.'.$this->issue->project->team->uuid);
    }

    public function broadcastAs(): string
    {
        return 'issue.surfaced';
    }

    /** @return array<string, string|bool|null> */
    public function broadcastWith(): array
    {
        return [
            'project' => $this->issue->project->uuid,
            'project_name' => $this->issue->project->name,
            'issue' => $this->issue->uuid,
            'title' => mb_substr($this->issue->title, 0, 200),
            'message' => mb_substr($this->issue->message, 0, 200),
            'kind' => $this->kind,
            'regression' => $this->kind === 'regression',
        ];
    }
}
