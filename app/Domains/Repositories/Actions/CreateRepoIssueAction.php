<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Actions;

use App\Domains\Issues\Actions\RecordIssueEventAction;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Models\Issue;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Triage\ClaudeTriageWorkflow;
use App\Domains\Repositories\Exceptions\PublicRepositoryNotAllowedException;
use App\Domains\Repositories\Exceptions\RepositoryNotLinkedException;
use App\Domains\Repositories\Providers\RepositoryDriverRegistry;
use App\Models\User;

/**
 * Idempotent: an issue that already has a repo issue returns that one.
 */
class CreateRepoIssueAction
{
    public function __construct(
        private readonly RepositoryDriverRegistry $drivers,
        private readonly BroadcastProjectChangeAction $broadcast,
        private readonly RecordIssueEventAction $history,
    ) {
    }

    public function execute(Issue $issue, ?string $title = null, ?string $body = null, ?User $by = null, ?McpToken $token = null): string
    {
        if ($issue->external_issue_url !== null) {
            return $issue->external_issue_url;
        }

        $repository = $issue->project->repository;

        if ($repository === null) {
            throw RepositoryNotLinkedException::forProject($issue->project->name);
        }

        $driver = $this->drivers->for($repository->provider);

        if (! $repository->allow_public && $driver->verify($repository)) {
            throw PublicRepositoryNotAllowedException::forRepository($repository->repository);
        }

        $url = $driver->createIssue(
            $repository,
            '['.$issue->reference().'] '.($title ?: $issue->title.': '.mb_substr($issue->message, 0, 120)),
            $this->body($issue, $body),
            // Always "lumberjack", so the triage workflow answers comments on it.
            array_values(array_unique([...($repository->labels ?? []), ClaudeTriageWorkflow::LABEL])),
        );

        $issue->update(['external_issue_provider' => $repository->provider->value, 'external_issue_url' => $url]);
        $this->history->execute($issue, IssueEventType::RepoIssueCreated, $by, $token, ['url' => $url]);
        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);

        return $url;
    }

    private function body(Issue $issue, ?string $body): string
    {
        $occurrence = $issue->occurrences()->latest('occurred_at')->first();
        $frames = collect($occurrence->stacktrace ?? [])
            ->filter(fn (array $f): bool => (bool) ($f['in_app'] ?? false))
            ->take(5)
            ->map(fn (array $f): string => ($f['file'] ?? '?').':'.($f['line'] ?? '?').(isset($f['function']) ? ' '.$f['function'].'()' : ''))
            ->implode("\n");

        return implode("\n\n", array_filter([
            $body,
            '**'.$issue->title.'**'."\n".$issue->message,
            $frames !== '' ? "```\n".$frames."\n```" : null,
            __('repositories.issue_footer', [
                'environment' => $occurrence->environment ?? '-',
                'release' => $issue->last_release ?? '-',
                'count' => $issue->occurrences_count,
                'url' => route('issues.show', [$issue->project, $issue]),
            ]),
        ]));
    }
}
