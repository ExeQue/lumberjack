<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use Illuminate\Support\Facades\Cache;

/**
 * An open issue escalates when it suddenly happens far more often than it
 * usually does: at least MIN occurrences in WINDOW minutes, and FACTOR times
 * its usual rate over the last day. It stays escalating for ACTIVE minutes.
 */
class DetectEscalationAction
{
    public function __construct(
        private readonly RecordIssueEventAction $history,
        private readonly ReturnIgnoredIssueAction $returnIgnored,
    ) {
    }

    public const int WINDOW_MINUTES = 5;

    public const int MIN = 10;

    public const int FACTOR = 5;

    public const int ACTIVE_MINUTES = 60;

    // ponytail: one count per issue every 30 seconds, so a flood does not cost a query per occurrence.
    public const int CHECK_EVERY_SECONDS = 30;

    public function execute(Issue $issue, ?string $environment): bool
    {
        $waiting = $issue->status === IssueStatus::Ignored && $issue->ignore_until_escalation;

        if ((! $issue->status->isOpen() && ! $waiting) || $issue->isEscalating()) {
            return false;
        }

        if (! Cache::add('issues:escalation-check:'.$issue->id, true, self::CHECK_EVERY_SECONDS)) {
            return false;
        }

        $now = now();
        $windowStart = $now->copy()->subMinutes(self::WINDOW_MINUTES);
        $burst = Occurrence::query()->where('issue_id', $issue->id)->where('occurred_at', '>=', $windowStart)->count();

        if ($burst < self::MIN) {
            return false;
        }

        $before = Occurrence::query()->where('issue_id', $issue->id)
            ->where('occurred_at', '>=', $now->copy()->subDay())->where('occurred_at', '<', $windowStart)->count();
        $usual = $before / ((24 * 60 - self::WINDOW_MINUTES) / self::WINDOW_MINUTES);

        if ($burst < self::FACTOR * $usual) {
            return false;
        }

        // An issue ignored until it escalates comes back instead.
        if ($waiting) {
            $issue->forceFill(['escalated_at' => $now])->save();
            $this->returnIgnored->execute($issue, 'escalation', $environment, ['burst' => $burst, 'minutes' => self::WINDOW_MINUTES]);

            return true;
        }

        $issue->forceFill(['escalated_at' => $now, 'surfaced_at' => $now])->save();
        $this->history->execute($issue, IssueEventType::Escalated, data: ['burst' => $burst, 'minutes' => self::WINDOW_MINUTES]);
        IssueEscalated::dispatch($issue, $environment, $burst);

        return true;
    }
}
