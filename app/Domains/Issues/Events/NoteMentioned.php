<?php

declare(strict_types=1);

namespace App\Domains\Issues\Events;

use App\Domains\Issues\Models\IssueNote;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells one member, on their own channel, that a note tagged them.
 */
class NoteMentioned implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        private readonly IssueNote $note,
        private readonly string $userUuid,
        private readonly string $author,
        private readonly string $excerpt,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('user.'.$this->userUuid);
    }

    public function broadcastAs(): string
    {
        return 'note.mentioned';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        $issue = $this->note->issue;

        return [
            'project' => $issue?->project?->uuid,
            'project_name' => $issue?->project?->name,
            'issue' => $issue?->uuid,
            'title' => $issue !== null ? mb_substr($issue->title, 0, 200) : null,
            'author' => $this->author,
            'excerpt' => mb_substr($this->excerpt, 0, 200),
        ];
    }
}
