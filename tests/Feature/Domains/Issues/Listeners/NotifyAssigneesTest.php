<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\AssignIssueAction;
use App\Domains\Issues\Actions\BulkChangeIssueStatusAction;
use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueAssignmentChanged;
use App\Domains\Issues\Listeners\NotifyAssignees;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Notifications\IssueAssignmentNotification;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

covers(NotifyAssignees::class, IssueAssignmentNotification::class, IssueAssignmentChanged::class);

beforeEach(function () {
    Notification::fake();
    Event::fake([IssueAssignmentChanged::class]);
    $this->issue = Issue::factory()->create(['title' => 'Deadlock']);
    $team = $this->issue->project->team;
    $this->morten = User::factory()->inTeam($team)->create(['name' => 'Morten']);
    $this->sofie = User::factory()->inTeam($team)->create(['name' => 'Sofie']);
    $this->anna = User::factory()->inTeam($team)->create(['name' => 'Anna']);
    $this->toasted = fn (User $user, string $as) => Event::assertDispatched(IssueAssignmentChanged::class, fn (IssueAssignmentChanged $e) => $e->broadcastOn()->name === 'private-user.'.$user->uuid && $e->broadcastAs() === $as);
});

describe('assignment notifications', function () {
    it('mails and toasts the new assignee only', function () {
        app(AssignIssueAction::class)->execute($this->issue, $this->sofie, $this->morten);

        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, fn (IssueAssignmentNotification $n) => $n->assigned
            && $n->toMail($this->sofie)->subject === 'Morten assigned you to Deadlock');
        Notification::assertNotSentTo([$this->morten, $this->anna], IssueAssignmentNotification::class);
        ($this->toasted)($this->sofie, 'issue.assigned');
        Event::assertDispatchedTimes(IssueAssignmentChanged::class, 1);
    });

    it('tells the new assignee and the one taken off', function () {
        $this->issue->update(['assigned_user_id' => $this->anna->id]);

        app(AssignIssueAction::class)->execute($this->issue, $this->sofie, $this->morten);

        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, fn ($n) => $n->toMail($this->sofie)->subject === 'Morten assigned you to Deadlock');
        Notification::assertSentTo($this->anna, IssueAssignmentNotification::class, fn ($n) => ! $n->assigned && $n->toMail($this->anna)->subject === 'Morten took you off Deadlock');
        ($this->toasted)($this->anna, 'issue.unassigned');
    });

    it('tells nobody about their own change', function () {
        app(AssignIssueAction::class)->execute($this->issue, $this->morten, $this->morten);
        app(AssignIssueAction::class)->execute($this->issue, null, $this->morten);

        Notification::assertNothingSent();
        Event::assertNotDispatched(IssueAssignmentChanged::class);
    });

    it('names an MCP token as who did it', function () {
        $token = McpToken::factory()->create(['name' => 'Claude']);

        app(AssignIssueAction::class)->execute($this->issue, $this->sofie, token: $token);

        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, fn ($n) => $n->toMail($this->sofie)->subject === 'Claude (MCP) assigned you to Deadlock');
    });

    it('stays quiet when starting work assigns the one who started it', function () {
        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::InProgress, $this->morten);

        expect($this->issue->refresh()->assigned_user_id)->toBe($this->morten->id);
        Notification::assertNothingSent();
    });
});

describe('unassigning on resolve', function () {
    it('takes a resolved issue off its assignee, in the history, and tells them', function () {
        $this->issue->update(['assigned_user_id' => $this->sofie->id]);

        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->morten);

        expect($this->issue->refresh()->assigned_user_id)->toBeNull()
            ->and($this->issue->events()->where('type', IssueEventType::Assigned)->sole()->data)->toBe(['from' => 'Sofie', 'to' => null]);
        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, fn ($n) => $n->toMail($this->sofie)->subject === 'Morten resolved Deadlock and took you off it');
        ($this->toasted)($this->sofie, 'issue.unassigned');
    });

    it('does not tell the one who resolved their own issue, and leaves ignored issues assigned', function () {
        $other = Issue::factory()->for($this->issue->project)->create(['assigned_user_id' => $this->sofie->id]);
        $this->issue->update(['assigned_user_id' => $this->morten->id]);

        app(ChangeIssueStatusAction::class)->execute($this->issue, IssueStatus::Resolved, $this->morten);
        app(ChangeIssueStatusAction::class)->execute($other, IssueStatus::Ignored, $this->morten);

        expect($this->issue->refresh()->assigned_user_id)->toBeNull()
            ->and($other->refresh()->assigned_user_id)->toBe($this->sofie->id);
        Notification::assertNothingSent();
    });

    it('clears every assignee in a bulk resolve and sends each person one message', function () {
        $project = $this->issue->project;
        $sofies = Issue::factory()->for($project)->count(3)->create(['assigned_user_id' => $this->sofie->id]);
        $annas = Issue::factory()->for($project)->create(['assigned_user_id' => $this->anna->id]);

        app(BulkChangeIssueStatusAction::class)->execute($project, [...$sofies->pluck('uuid')->all(), $annas->uuid], IssueStatus::Resolved, $this->morten);

        expect(Issue::query()->whereNotNull('assigned_user_id')->count())->toBe(0);
        Notification::assertSentToTimes($this->sofie, IssueAssignmentNotification::class, 1);
        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, fn ($n) => $n->issues->count() === 3
            && $n->toMail($this->sofie)->subject === 'Morten resolved 3 issues in '.$project->name.' and took you off them');
        Notification::assertSentToTimes($this->anna, IssueAssignmentNotification::class, 1);
        Event::assertDispatchedTimes(IssueAssignmentChanged::class, 2);
    });
});
