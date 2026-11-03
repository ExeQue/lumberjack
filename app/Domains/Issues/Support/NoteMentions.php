<?php

declare(strict_types=1);

namespace App\Domains\Issues\Support;

use App\Domains\Issues\Models\IssueNote;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who a note tags: a team member as <@id>, as Discord does, or an agent's MCP
 * token as <@mcp:id>. Ids keep a tag working after a rename; a tag of anyone
 * outside the team is text.
 */
final class NoteMentions
{
    public const string PATTERN = '/<@(mcp:)?(\d+)>/';

    /**
     * @return list<int>
     */
    public static function ids(string $body): array
    {
        return self::tagged($body, agents: false);
    }

    /**
     * The MCP tokens a note tags.
     *
     * @return list<int>
     */
    public static function agentIds(string $body): array
    {
        return self::tagged($body, agents: true);
    }

    /**
     * @return list<int>
     */
    private static function tagged(string $body, bool $agents): array
    {
        preg_match_all(self::PATTERN, $body, $matches, PREG_SET_ORDER);

        $ids = array_map(fn (array $m): int => (int) $m[2], array_filter($matches, fn (array $m): bool => ($m[1] !== '') === $agents));

        return array_values(array_unique($ids));
    }

    /**
     * The tagged members of the issue's team, without the note's own author.
     *
     * @return Collection<int, User>
     */
    public function users(IssueNote $note): Collection
    {
        $ids = array_diff(self::ids($note->body), array_filter([$note->user_id]));
        $team = $note->issue?->project?->team;

        if ($ids === [] || $team === null) {
            return new Collection();
        }

        return $team->members()->whereIn('users.id', $ids)->get();
    }

    /**
     * Who wrote the note, as mails and toasts name them: a person, or an agent's MCP token.
     */
    public function author(IssueNote $note): string
    {
        return $note->mcpToken !== null
            ? (string) __('alerts.messages.via_mcp', ['token' => $note->mcpToken->name])
            : ($note->user->name ?? (string) __('alerts.messages.unknown_author'));
    }

    /**
     * The note as text, every tag of a team member written as @Name.
     */
    public function render(string $body, ?Team $team): string
    {
        $ids = self::ids($body);
        $agentIds = self::agentIds($body);

        if (($ids === [] && $agentIds === []) || $team === null) {
            return $body;
        }

        $names = $ids === [] ? collect() : $team->members()->whereIn('users.id', $ids)->pluck('name', 'users.id');
        $agents = $agentIds === [] ? collect() : $team->mcpTokens()->whereKey($agentIds)->pluck('name', 'id');

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($names, $agents): string {
            $id = (int) $m[2];

            if ($m[1] !== '') {
                return isset($agents[$id]) ? '@'.$agents[$id].' (MCP)' : $m[0];
            }

            return isset($names[$id]) ? '@'.$names[$id] : $m[0];
        }, $body);
    }
}
