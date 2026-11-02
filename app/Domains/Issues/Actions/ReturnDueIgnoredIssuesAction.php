<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;

/**
 * Brings back the issues that were ignored for a while, once that while is over.
 */
class ReturnDueIgnoredIssuesAction
{
    public function __construct(
        private readonly ReturnIgnoredIssueAction $returnIgnored,
    ) {
    }

    public function execute(): int
    {
        $returned = 0;

        Issue::query()
            ->whereStatus(IssueStatus::Ignored)
            ->whereNotNull('ignore_until_at')
            ->where('ignore_until_at', '<=', now())
            ->eachById(function (Issue $issue) use (&$returned): void {
                $this->returnIgnored->execute($issue, 'time');
                $returned++;
            });

        return $returned;
    }
}
