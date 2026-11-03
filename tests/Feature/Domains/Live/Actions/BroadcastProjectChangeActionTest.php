<?php

declare(strict_types=1);

use App\Domains\Live\Actions\BroadcastProjectChangeAction;
use App\Domains\Live\Enums\ChangeKind;
use App\Domains\Live\Events\ProjectChanged;
use App\Domains\Live\Jobs\BroadcastTrailingProjectChange;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

covers(BroadcastProjectChangeAction::class, BroadcastTrailingProjectChange::class);

describe('live broadcasts', function () {
    it('sends the first change at once and one more after a burst, so the last change is seen', function () {
        Event::fake([ProjectChanged::class]);
        Queue::fake();
        $project = Project::factory()->create();
        $action = app(BroadcastProjectChangeAction::class);

        foreach (range(1, 5) as $_) {
            $action->execute($project->id, ChangeKind::Health);
        }

        Event::assertDispatchedTimes(ProjectChanged::class, 1);
        Queue::assertPushed(BroadcastTrailingProjectChange::class, 1);

        (new BroadcastTrailingProjectChange($project->id, ChangeKind::Health))->handle($action);

        Event::assertDispatchedTimes(ProjectChanged::class, 2);
    });

    it('sends no trailing broadcast for a lone change', function () {
        Event::fake([ProjectChanged::class]);
        Queue::fake();

        app(BroadcastProjectChangeAction::class)->execute(Project::factory()->create()->id, ChangeKind::Issues);

        Event::assertDispatchedTimes(ProjectChanged::class, 1);
        Queue::assertNothingPushed();
    });

    it('waits longest between performance broadcasts, which make dashboards recompute their statistics', function () {
        expect(BroadcastProjectChangeAction::window(ChangeKind::Performance))->toBe(10)
            ->and(BroadcastProjectChangeAction::window(ChangeKind::Issues))->toBe(1);

        Event::fake([ProjectChanged::class]);
        Queue::fake();
        $project = Project::factory()->create();
        $action = app(BroadcastProjectChangeAction::class);

        $action->execute($project->id, ChangeKind::Performance, $project->uuid);
        $this->travel(5)->seconds();
        $action->execute($project->id, ChangeKind::Performance, $project->uuid);

        Event::assertDispatchedTimes(ProjectChanged::class, 1);
        Queue::assertPushed(BroadcastTrailingProjectChange::class, fn (BroadcastTrailingProjectChange $job): bool => $job->kind === ChangeKind::Performance);
    });

    it('throttles the occurrences of one issue apart from the rest of the project', function () {
        Event::fake([ProjectChanged::class]);
        Queue::fake();
        $project = Project::factory()->create();
        $action = app(BroadcastProjectChangeAction::class);

        $action->execute($project->id, ChangeKind::Issues);
        $action->issueTouched($project->id, 'issue-a');
        $action->issueTouched($project->id, 'issue-a');
        $action->issueTouched($project->id, 'issue-b');

        Event::assertDispatchedTimes(ProjectChanged::class, 3);
        Event::assertDispatched(ProjectChanged::class, fn (ProjectChanged $event): bool => $event->broadcastAs() === 'issue.updated' && $event->broadcastWith()['issue'] === 'issue-a');
        Queue::assertPushed(BroadcastTrailingProjectChange::class, fn (BroadcastTrailingProjectChange $job): bool => $job->issueUuid === 'issue-a');
    });
});
