<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Actions\ReturnDueIgnoredIssuesAction;
use App\Domains\Issues\Actions\ReturnIgnoredIssueAction;
use App\Domains\Issues\Data\IgnoreCondition;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueRegressed;
use App\Domains\Issues\Models\Occurrence;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

covers(ReturnIgnoredIssueAction::class, ReturnDueIgnoredIssuesAction::class, IgnoreCondition::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->record = fn () => app(RecordOccurrenceAction::class)->execute($this->project, new IssueSignal(IssueSource::Server, 'RuntimeException', 'Card declined', null, null, [], [], CarbonImmutable::now(), environment: 'production'));
    $this->issue = ($this->record)()->issue;
    $this->ignore = fn (string $choice) => app(ChangeIssueStatusAction::class)->execute($this->issue->refresh(), IssueStatus::Ignored, until: IgnoreCondition::fromChoice($choice));
});

describe('an ignored issue coming back', function () {
    it('waits for an escalation by default', function () {
        app(ChangeIssueStatusAction::class)->execute($this->issue->refresh(), IssueStatus::Ignored);

        expect($this->issue->refresh()->ignore_until_escalation)->toBeTrue();
    });

    it('stays ignored for good when that is chosen, however often it happens', function () {
        ($this->ignore)('forever');
        foreach (range(1, 20) as $_) {
            ($this->record)();
        }

        expect($this->issue->refresh()->status)->toBe(IssueStatus::Ignored);
    });

    it('comes back after the number of further occurrences it was ignored for', function () {
        Event::fake([IssueRegressed::class]);
        ($this->ignore)('count:10');

        foreach (range(1, 9) as $_) {
            ($this->record)();
        }
        expect($this->issue->refresh()->status)->toBe(IssueStatus::Ignored);

        ($this->record)();
        $issue = $this->issue->refresh();

        expect($issue->status)->toBe(IssueStatus::Open)
            ->and($issue->returned_at)->not->toBeNull()
            ->and($issue->ignore_until_count)->toBeNull()
            ->and($issue->events()->latest('id')->first()?->data)->toMatchArray(['reason' => 'count']);
        Event::assertDispatched(IssueRegressed::class);
    });

    it('comes back once the time it was ignored for is over', function () {
        ($this->ignore)('1h');

        expect(app(ReturnDueIgnoredIssuesAction::class)->execute())->toBe(0);

        $this->travel(61)->minutes();

        expect(app(ReturnDueIgnoredIssuesAction::class)->execute())->toBe(1)
            ->and($this->issue->refresh()->status)->toBe(IssueStatus::Open);
    });

    it('comes back when it escalates, if it was ignored until then', function () {
        ($this->ignore)('escalation');
        foreach (range(1, 12) as $i) {
            Occurrence::factory()->for($this->issue)->create(['project_id' => $this->project->id, 'occurred_at' => now()->subSeconds($i * 10)]);
        }

        // The check runs at most every 30 seconds per issue; the first occurrence above started that clock.
        Cache::flush();

        expect(app(DetectEscalationAction::class)->execute($this->issue->refresh(), 'production'))->toBeTrue()
            ->and($this->issue->refresh()->status)->toBe(IssueStatus::Open)
            ->and($this->issue->events()->latest('id')->first()?->data)->toMatchArray(['reason' => 'escalation']);
    });

    it('forgets the condition when someone changes the status by hand', function () {
        ($this->ignore)('24h');
        app(ChangeIssueStatusAction::class)->execute($this->issue->refresh(), IssueStatus::Open);

        expect($this->issue->refresh()->ignore_until_at)->toBeNull();
    });
});
