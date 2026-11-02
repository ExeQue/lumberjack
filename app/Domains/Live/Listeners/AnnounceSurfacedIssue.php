<?php

declare(strict_types=1);

namespace App\Domains\Live\Listeners;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Events\IssueCreated;
use App\Domains\Issues\Events\IssueEscalated;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Live\Events\IssueSurfaced;
use Throwable;

class AnnounceSurfacedIssue
{
    public function handle(IssueCreated|IssueRegressed|IssueEscalated $event): void
    {
        // Browser errors are too noisy to sound off for, as they are for alerts.
        if ($event->issue->source === IssueSource::Browser) {
            return;
        }

        try {
            broadcast(new IssueSurfaced($event->issue, match (true) {
                $event instanceof IssueRegressed => 'regression',
                $event instanceof IssueEscalated => 'escalation',
                default => 'new',
            }));
        } catch (Throwable $e) {
            // A toast is a nicety; Reverb being down must not cost the batch.
            report($e);
        }
    }
}
