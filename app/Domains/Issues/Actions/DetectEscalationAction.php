<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Occurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    /** Seconds the occurrence count of the day before the window is reused. */
    public const int BASELINE_SECONDS = 300;

    /**
     * Seconds between two recounts of the window from the database. In between, each
     * occurrence only bumps a counter per issue and minute in the cache, so a flood costs
     * no query per occurrence. The recount also catches anything the counters missed (an
     * issue that was resolved or escalating is not counted while it is).
     */
    public const int RESYNC_SECONDS = (self::WINDOW_MINUTES + 1) * 60;

    public function execute(Issue $issue, ?string $environment, ?CarbonInterface $occurredAt = null): bool
    {
        $waiting = $issue->status === IssueStatus::Ignored && $issue->ignore_until_escalation;

        if ((! $issue->status->isOpen() && ! $waiting) || $issue->isEscalating()) {
            return false;
        }

        $now = now();
        $windowStart = $now->copy()->subMinutes(self::WINDOW_MINUTES);
        $burst = $this->burst($issue, $windowStart, $occurredAt ?? $now);

        if ($burst < self::MIN) {
            return false;
        }

        $before = (int) Cache::remember('issues:escalation-baseline:'.$issue->id, self::BASELINE_SECONDS, fn (): int => Occurrence::query()
            ->where('issue_id', $issue->id)
            ->where('occurred_at', '>=', $now->copy()->subDay())
            ->where('occurred_at', '<', $windowStart)
            ->count());
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

    /**
     * Occurrences of the issue since the window start, counted per minute in the cache. The
     * minute the window starts in counts whole, so the window is up to a minute longer.
     */
    private function burst(Issue $issue, CarbonInterface $windowStart, CarbonInterface $occurredAt): int
    {
        $prefix = 'issues:burst:'.$issue->id.':';
        $first = intdiv($windowStart->getTimestamp(), 60);
        $last = intdiv(now()->getTimestamp(), 60);
        $ttl = self::RESYNC_SECONDS + 60;

        if (Cache::add($prefix.'synced', true, self::RESYNC_SECONDS)) {
            // The occurrence just stored is in the database, so the recount includes it.
            $counts = Occurrence::query()
                ->where('issue_id', $issue->id)
                ->where('occurred_at', '>=', CarbonImmutable::createFromTimestamp($first * 60))
                ->selectRaw('floor(extract(epoch from occurred_at) / 60)::bigint as minute, count(*) as n')
                ->groupBy('minute')
                ->pluck('n', 'minute');

            Cache::putMany(collect(range($first, $last))
                ->mapWithKeys(fn (int $minute): array => [$prefix.$minute => (int) ($counts[$minute] ?? 0)])
                ->all(), $ttl);
        } else {
            $minute = min($last, intdiv($occurredAt->getTimestamp(), 60));

            if ($minute >= $first) {
                Cache::add($prefix.$minute, 0, $ttl);
                Cache::increment($prefix.$minute);
            }
        }

        return (int) array_sum(array_map(intval(...), Cache::many(array_map(fn (int $minute): string => $prefix.$minute, range($first, $last)))));
    }
}
