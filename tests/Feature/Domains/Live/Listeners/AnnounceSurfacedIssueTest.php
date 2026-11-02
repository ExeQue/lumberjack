<?php

declare(strict_types=1);

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Events\IssueCreated;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Issues\Models\Issue;
use App\Domains\Live\Events\IssueSurfaced;
use App\Domains\Live\Listeners\AnnounceSurfacedIssue;
use Illuminate\Support\Facades\Event;

covers(AnnounceSurfacedIssue::class, IssueSurfaced::class);

describe('announcing a surfaced issue', function () {
    beforeEach(fn () => Event::fake([IssueSurfaced::class]));

    it('tells the team about a new server error, with what the toast needs', function () {
        $issue = Issue::factory()->create(['title' => 'RuntimeException', 'message' => 'Card declined']);

        app(AnnounceSurfacedIssue::class)->handle(new IssueCreated($issue, 'production'));

        Event::assertDispatched(IssueSurfaced::class, function (IssueSurfaced $event) use ($issue): bool {
            return $event->broadcastOn()->name === 'private-team.'.$issue->project->team->uuid
                && $event->broadcastWith() === [
                    'project' => $issue->project->uuid,
                    'project_name' => $issue->project->name,
                    'issue' => $issue->uuid,
                    'title' => 'RuntimeException',
                    'message' => 'Card declined',
                    'kind' => 'new',
                    'regression' => false,
                ];
        });
    });

    it('marks a regression as one', function () {
        app(AnnounceSurfacedIssue::class)->handle(new IssueRegressed(Issue::factory()->create(), 'production'));

        Event::assertDispatched(IssueSurfaced::class, fn (IssueSurfaced $event): bool => $event->broadcastWith()['regression'] === true);
    });

    it('stays quiet about browser errors', function () {
        app(AnnounceSurfacedIssue::class)->handle(new IssueCreated(Issue::factory()->create(['source' => IssueSource::Browser]), 'production'));

        Event::assertNotDispatched(IssueSurfaced::class);
    });
});
