<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\IssueNote;
use App\Domains\Issues\Support\NoteMentions;
use App\Domains\Mcp\Actions\ReadMentionsAction;
use App\Domains\Mcp\Actions\ReadTriageInboxAction;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TriageInbox extends Tool
{
    use UsesMcpContext;

    protected string $name = 'triage_inbox';

    protected string $description = 'Your work queue, kept short. "issues": the open issues (not in progress) you have not handled since they last surfaced, oldest first, as number, title, a short message, location, flags, occurrence count and labels; get_issue has the stacktrace, context, repository and history. "mentions": notes that tagged you (<@mcp:id>) that you have not answered. A note from you on an issue (add_issue_note, or with set_issue_status) marks it handled; anything left without one comes back on the next call. At most 50 issues; call again while has_more is true.';

    public function __construct(
        private readonly ReadTriageInboxAction $inbox,
        private readonly ReadMentionsAction $mentions,
        private readonly NoteMentions $noteMentions,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }

    public function handle(Request $request): Response
    {
        $token = $this->context()->token();
        $projectIds = array_values(array_map('intval', $this->context()->projects()->modelKeys()));
        $result = $this->inbox->execute($token, $projectIds);

        // Lean on purpose: what is needed to pick the next issue. get_issue has the rest.
        return Response::json([
            'has_more' => $result['has_more'],
            'mentions' => $this->mentions->execute($token, $projectIds)->map(fn (IssueNote $note): array => [
                'issue' => $note->issue?->reference(),
                'title' => $note->issue?->title,
                'author' => $note->mcpToken !== null ? $note->mcpToken->name.' (MCP)' : $note->user?->name,
                'body' => $this->noteMentions->render($note->body, $note->issue?->project?->team),
            ])->all(),
            'issues' => $result['issues']->map(fn (Issue $issue): array => array_filter([
                'issue' => $issue->reference(),
                'title' => $issue->title,
                'message' => mb_strlen($issue->message) > 200 ? mb_substr($issue->message, 0, 200).'…' : $issue->message,
                'location' => $issue->file !== null ? $issue->file.($issue->line !== null ? ':'.$issue->line : '') : $issue->log_channel,
                'source' => $issue->source->value,
                'flags' => array_keys(array_filter(['regressed' => $issue->isRegressed(), 'escalating' => $issue->isEscalating(), 'returned' => $issue->returned_at !== null])) ?: null,
                'occurrences' => $issue->occurrences_count,
                'last_seen_at' => $issue->last_seen_at->toIso8601String(),
                'labels' => $issue->labels->pluck('name')->all() ?: null,
            ], fn ($value): bool => $value !== null))->all(),
        ]);
    }
}
