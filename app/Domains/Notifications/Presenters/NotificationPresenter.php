<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Presenters;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Notification center rows: what the notification said, where it opens, and its team
 * when the user is in several.
 */
class NotificationPresenter
{
    /** @var array<string, string>|null team uuid => name, when the user is in several */
    private ?array $teams = null;

    public function __construct(
        private readonly User $user,
    ) {
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array{id: string, kind: string, title: string, body: string, url: string, project: string|null, team: string|null, read: bool, created_at: string|null}>
     */
    public function present(iterable $notifications): array
    {
        $teams = $this->teams();

        return array_values(array_map(function (DatabaseNotification $n) use ($teams): array {
            /** @var array<string, mixed> $data */
            $data = $n->data;
            $team = is_string($data['team_uuid'] ?? null) ? ($teams[$data['team_uuid']] ?? null) : null;

            return [
                'id' => (string) $n->id,
                'kind' => $n->type,
                'title' => (string) ($data['title'] ?? ''),
                'body' => (string) ($data['body'] ?? ''),
                'url' => (string) ($data['url'] ?? route('projects.index')),
                'project' => is_string($data['project'] ?? null) ? $data['project'] : null,
                'team' => $team,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ];
        }, $notifications instanceof Collection ? $notifications->all() : [...$notifications]));
    }

    /**
     * @return array<string, string>
     */
    private function teams(): array
    {
        if ($this->teams !== null) {
            return $this->teams;
        }

        /** @var array<string, string> $teams */
        $teams = $this->user->teams()->pluck('teams.name', 'teams.uuid')->all();

        return $this->teams = count($teams) > 1 ? $teams : [];
    }
}
