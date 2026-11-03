<?php

declare(strict_types=1);

use App\Domains\Alerts\Jobs\SendIssueAlerts;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Live\Events\ProjectChanged;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

covers(RecordOccurrenceAction::class);

describe('after the commit', function () {
    it('queues the new issue alert and broadcasts only once the issue is committed', function () {
        Queue::fake([SendIssueAlerts::class]);
        Event::fake([ProjectChanged::class]);
        $project = Project::factory()->create();

        DB::transaction(function () use ($project): void {
            app(RecordOccurrenceAction::class)->execute($project, new IssueSignal(IssueSource::Server, 'RuntimeException', 'Boom', null, null, [], [], CarbonImmutable::now()));

            Queue::assertNotPushed(SendIssueAlerts::class);
            Event::assertNotDispatched(ProjectChanged::class);
        });

        Queue::assertPushed(SendIssueAlerts::class, 1);
        Event::assertDispatched(ProjectChanged::class);
    });
});
