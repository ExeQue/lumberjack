<?php

declare(strict_types=1);

namespace App\Domains\Issues\Presenters;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueEvent;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Models\Label;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one shape of an issue that both the UI and the MCP tools hand out.
 */
class IssuePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Issue $issue): array
    {
        return [
            'id' => $issue->uuid,
            'number' => $issue->number,
            'reference' => $issue->number !== null ? $issue->reference() : null,
            'project' => $issue->relationLoaded('project') ? $issue->project->slug : null,
            'title' => $issue->title,
            'message' => $issue->message,
            'source' => $issue->source->value,
            'status' => $issue->status->value,
            'regressed' => $issue->isRegressed(),
            'escalating' => $issue->isEscalating(),
            'returned' => $issue->status->isOpen() && $issue->returned_at !== null && $issue->returned_at->greaterThan(now()->subDay()),
            'ignore_until' => $issue->status === IssueStatus::Ignored ? array_filter([
                'at' => $issue->ignore_until_at?->toIso8601String(),
                'occurrences_left' => $issue->ignore_until_count !== null ? max(0, $issue->ignore_until_count - $issue->occurrences_count) : null,
                'escalation' => $issue->ignore_until_escalation ?: null,
            ], fn ($value): bool => $value !== null) ?: null : null,
            'new' => $issue->first_seen_at->greaterThan(now()->subDay()),
            'location' => $issue->file !== null ? $issue->file.($issue->line !== null ? ':'.$issue->line : '') : $issue->log_channel,
            'occurrences' => $issue->occurrences_count,
            'users' => $issue->users_count,
            'notes' => (int) ($issue->notes_count ?? 0),
            'labels' => $issue->relationLoaded('labels') ? $issue->labels->map(fn (Label $l): array => $l->toSummary())->values()->all() : [],
            'first_seen_at' => $issue->first_seen_at->toIso8601String(),
            'last_seen_at' => $issue->last_seen_at->toIso8601String(),
            'last_release' => $issue->last_release,
            'assignee' => $issue->assignee !== null ? ['id' => $issue->assignee->id, 'name' => $issue->assignee->name] : null,
            'repo_issue_url' => $issue->external_issue_url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function occurrence(Occurrence $occurrence): array
    {
        $context = $occurrence->context ?? [];
        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        $user = is_array($context['user'] ?? null) ? $context['user'] : [];

        return [
            'request' => $request === [] ? null : [
                'method' => is_string($request['method'] ?? null) ? $request['method'] : null,
                'route' => is_string($request['route'] ?? null) ? $request['route'] : (is_string($request['url'] ?? null) ? $request['url'] : null),
            ],
            'user_id' => is_scalar($user['id'] ?? null) ? (string) $user['id'] : null,
            'id' => $occurrence->id,
            'occurred_at' => $occurrence->occurred_at->format('Y-m-d\TH:i:s.vP'),
            'trace_id' => $occurrence->trace_id,
            'environment' => $occurrence->environment,
            'release' => $occurrence->release,
            'host' => $occurrence->host,
            'stacktrace' => $occurrence->stacktrace ?? [],
            'context' => $occurrence->context ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function note(IssueNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'status' => $note->status?->value,
            'author' => $note->user?->name,
            'via_mcp' => $note->mcp_token_id !== null ? $note->mcpToken?->name : null,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    /**
     * Loads the issue's notes, newest first, and its latest events with who wrote each: a
     * query for each, and one for the people and one for the agents of both.
     */
    public function loadHistory(Issue $issue, int $limit = 100): Issue
    {
        if ($issue->relationLoaded('notes') && $issue->relationLoaded('events')) {
            return $issue;
        }

        $issue->load([
            'notes' => fn (HasMany $notes) => $notes->latest(),
            'events' => fn (HasMany $events) => $events->latest('occurred_at')->limit($limit),
        ]);

        // Events and notes name their author by the same keys, so one eager load fills both.
        new Collection([...$issue->events, ...$issue->notes])->load(['user', 'mcpToken']);

        return $issue;
    }

    /**
     * Everything that happened to the issue, newest first: its events and its notes.
     *
     * @return list<array{type: string, actor: string|null, via_mcp: bool, data: array<string, mixed>|null, at: string}>
     */
    public function history(Issue $issue, int $limit = 100): array
    {
        $actor = fn (?User $user, ?McpToken $token): ?string => $token !== null ? $token->name : $user?->name;
        $this->loadHistory($issue, $limit);

        $events = $issue->events->take($limit)
            ->map(fn (IssueEvent $e): array => [
                'type' => $e->type->value,
                'actor' => $actor($e->user, $e->mcpToken),
                'via_mcp' => $e->mcp_token_id !== null,
                'data' => $e->data,
                'at' => $e->occurred_at->format('Y-m-d\\TH:i:s.vP'),
            ]);

        $notes = $issue->notes->take($limit)
            ->map(fn (IssueNote $n): array => [
                'type' => 'note',
                'actor' => $actor($n->user, $n->mcpToken),
                'via_mcp' => $n->mcp_token_id !== null,
                'data' => ['body' => $n->body, 'status' => $n->status?->value],
                'at' => ($n->created_at ?? now())->format('Y-m-d\\TH:i:s.vP'),
            ]);

        // Notes keep whole seconds; within one, the event goes first and its note right after.
        return array_values($events->concat($notes)
            ->sortByDesc(fn (array $entry): string => substr($entry['at'], 0, 19).($entry['type'] === 'note' ? '0' : '1'))
            ->take($limit)->values()->all());
    }
}
