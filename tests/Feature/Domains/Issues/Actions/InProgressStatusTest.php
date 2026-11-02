<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Actions\RecordOccurrenceAction;
use App\Domains\Issues\Data\IssueSignal;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

covers(ChangeIssueStatusAction::class);

describe('in progress', function () {
    it('assigns an unassigned issue to whoever takes it in progress, and leaves an assignee alone', function () {
        $issue = Issue::factory()->create();
        $taker = User::factory()->inTeam($issue->project->team)->create();

        app(ChangeIssueStatusAction::class)->execute($issue, IssueStatus::InProgress, $taker);

        expect($issue->refresh()->status)->toBe(IssueStatus::InProgress)
            ->and($issue->in_progress_at)->not->toBeNull()
            ->and($issue->assigned_user_id)->toBe($taker->id);

        $other = User::factory()->inTeam($issue->project->team)->create();
        app(ChangeIssueStatusAction::class)->execute($issue, IssueStatus::Open, $other);
        app(ChangeIssueStatusAction::class)->execute($issue, IssueStatus::InProgress, $other);

        expect($issue->refresh()->assigned_user_id)->toBe($taker->id);
    });

    it('counts as open: on the open tab, in its count, and in the open builder', function () {
        $issue = Issue::factory()->create(['status' => IssueStatus::InProgress]);
        Issue::factory()->for($issue->project)->create();
        $user = User::factory()->inTeam($issue->project->team)->create();

        expect(Issue::query()->whereOpen()->count())->toBe(2);

        $this->actingAs($user)
            ->get(route('issues.index', ['project' => $issue->project]))
            ->assertInertia(fn (Assert $page) => $page->has('issues.data', 2)->where('counts.open', 2)->where('counts.in_progress', 1));

        $this->actingAs($user)
            ->get(route('issues.index', ['project' => $issue->project, 'status' => 'in_progress']))
            ->assertInertia(fn (Assert $page) => $page->has('issues.data', 1));
    });

    it('stays in progress when the error happens again', function () {
        $project = Project::factory()->create();
        $signal = fn (): IssueSignal => new IssueSignal(IssueSource::Server, 'RuntimeException', 'Card declined', 'app/Pay.php', 12, [], [], CarbonImmutable::now(), environment: 'production');

        $issue = app(RecordOccurrenceAction::class)->execute($project, $signal())->issue;
        app(ChangeIssueStatusAction::class)->execute($issue, IssueStatus::InProgress);
        app(RecordOccurrenceAction::class)->execute($project, $signal());

        expect($issue->refresh()->status)->toBe(IssueStatus::InProgress)
            ->and($issue->occurrences()->count())->toBe(2);
    });
});
