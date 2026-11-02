<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Issues\Models\Issue;
use App\Domains\Live\Actions\BroadcastProjectChangeAction;

/**
 * An ignored issue whose condition came true is open again: it resurfaces and
 * alerts like a regression, since something thought done with is back.
 */
class ReturnIgnoredIssueAction
{
    public function __construct(
        private readonly RecordIssueEventAction $history,
        private readonly BroadcastProjectChangeAction $broadcast,
    ) {
    }

    /**
     * @param  string  $reason  time, count or escalation
     * @param  array<string, mixed>  $data
     */
    public function execute(Issue $issue, string $reason, ?string $environment = null, array $data = []): Issue
    {
        if ($issue->status !== IssueStatus::Ignored) {
            return $issue;
        }

        $issue->forceFill([
            ...self::cleared(),
            'status' => IssueStatus::Open,
            'ignored_at' => null,
            'returned_at' => now(),
            'regressed_at' => now(),
            'surfaced_at' => now(),
        ])->save();

        $this->history->execute($issue, IssueEventType::Returned, data: ['reason' => $reason, ...$data]);
        IssueRegressed::dispatch($issue, $environment);
        $this->broadcast->issueUpdated($issue->project_id, $issue->uuid);

        return $issue;
    }

    /**
     * @return array{ignore_until_at: null, ignore_until_count: null, ignore_until_escalation: false}
     */
    public static function cleared(): array
    {
        return ['ignore_until_at' => null, 'ignore_until_count' => null, 'ignore_until_escalation' => false];
    }
}
