<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\BulkAssignIssuesAction;
use App\Domains\Issues\Actions\BulkLabelIssuesAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Events\IssueAssignmentChanged;
use App\Domains\Issues\Http\Requests\Concerns\TargetsSelectedIssues;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Issues\Notifications\IssueAssignmentNotification;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Teams\CreatesTeamMembers;

covers(BulkAssignIssuesAction::class, BulkLabelIssuesAction::class, TargetsSelectedIssues::class);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    Notification::fake();
    Event::fake([IssueAssignmentChanged::class]);
    ['user' => $this->morten, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
    $this->morten->update(['name' => 'Morten']);
    $this->project->update(['name' => 'Demo']);
    $this->sofie = User::factory()->inTeam($this->project->team)->create(['name' => 'Sofie']);
    $this->issues = Issue::factory()->for($this->project)->count(12)->create();
    $this->uuids = $this->issues->pluck('uuid')->all();
});

describe('assigning selected issues', function () {
    it('assigns them all and tells the assignee once, with ten listed', function () {
        $this->actingAs($this->morten)
            ->post(route('issues.bulk-assign', $this->project), ['user_id' => $this->sofie->id, 'issues' => $this->uuids])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Assigned 12 issues to Sofie.');

        expect(Issue::query()->where('assigned_user_id', $this->sofie->id)->count())->toBe(12);
        Notification::assertSentToTimes($this->sofie, IssueAssignmentNotification::class, 1);
        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, function (IssueAssignmentNotification $n): bool {
            $mail = $n->toMail($this->sofie);

            return $mail->subject === 'Morten assigned you to 12 issues in Demo'
                && count(array_filter($mail->introLines, fn (string $line): bool => str_contains($line, ' · RuntimeException'))) === 10
                && in_array('+2 more', $mail->introLines, true);
        });
        Event::assertDispatchedTimes(IssueAssignmentChanged::class, 1);
    });

    it('tells the people taken off once each, and nobody about their own issues', function () {
        $this->issues->take(3)->each->update(['assigned_user_id' => $this->sofie->id]);

        $this->actingAs($this->morten)->post(route('issues.bulk-assign', $this->project), ['user_id' => $this->morten->id, 'issues' => $this->uuids]);

        Notification::assertSentToTimes($this->sofie, IssueAssignmentNotification::class, 1);
        Notification::assertSentTo($this->sofie, IssueAssignmentNotification::class, fn ($n) => $n->toMail($this->sofie)->subject === 'Morten took you off 3 issues in Demo');
        Notification::assertNotSentTo($this->morten, IssueAssignmentNotification::class);
    });

    it('unassigns them', function () {
        $this->issues->each->update(['assigned_user_id' => $this->sofie->id]);

        $this->actingAs($this->morten)
            ->post(route('issues.bulk-assign', $this->project), ['user_id' => null, 'issues' => $this->uuids])
            ->assertInertiaFlash('toast.message', 'Unassigned 12 issues.');

        expect(Issue::query()->whereNotNull('assigned_user_id')->count())->toBe(0);
    });

    it('refuses an assignee from outside the team', function () {
        $this->actingAs($this->morten)
            ->post(route('issues.bulk-assign', $this->project), ['user_id' => User::factory()->create()->id, 'issues' => $this->uuids])
            ->assertSessionHasErrors('user_id');

        expect(Issue::query()->whereNotNull('assigned_user_id')->count())->toBe(0);
    });
});

describe('labeling selected issues', function () {
    beforeEach(fn () => $this->label = Label::factory()->for($this->project)->create(['name' => 'payments']));

    it('adds a label to the ones without it and removes it again', function () {
        $this->issues->first()->labels()->attach($this->label);

        $this->actingAs($this->morten)
            ->post(route('issues.bulk-labels', $this->project), ['label' => 'payments', 'issues' => $this->uuids])
            ->assertInertiaFlash('toast.message', 'Added payments to 11 issues.');

        expect($this->label->issues()->count())->toBe(12)
            ->and(Label::query()->count())->toBe(1);

        $this->actingAs($this->morten)
            ->post(route('issues.bulk-labels', $this->project), ['label' => 'payments', 'remove' => true, 'issues' => $this->uuids])
            ->assertInertiaFlash('toast.message', 'Removed payments from 12 issues.');

        expect($this->label->issues()->count())->toBe(0);
    });
});

describe('starting work on selected issues', function () {
    it('takes them in progress, assigns the unassigned ones to whoever started, and tells the others nothing', function () {
        $this->issues->first()->update(['assigned_user_id' => $this->sofie->id]);

        $this->actingAs($this->morten)
            ->post(route('issues.bulk-status', $this->project), ['status' => 'in_progress', 'issues' => $this->uuids])
            ->assertInertiaFlash('toast.message', 'Started work on 12 issues.');

        expect(Issue::query()->whereStatus(IssueStatus::InProgress)->count())->toBe(12)
            ->and(Issue::query()->where('assigned_user_id', $this->morten->id)->count())->toBe(11)
            ->and($this->issues->first()->refresh()->assigned_user_id)->toBe($this->sofie->id);
        Notification::assertNothingSent();
    });
});

describe('who may not', function () {
    it('forbids someone outside the team every bulk action', function (string $route, array $data) {
        $this->actingAs(User::factory()->inTeam()->create())
            ->post(route($route, $this->project), [...$data, 'issues' => $this->uuids])
            ->assertForbidden();
    })->with([
        'status' => ['issues.bulk-status', ['status' => 'resolved']],
        'assign' => ['issues.bulk-assign', ['user_id' => null]],
        'labels' => ['issues.bulk-labels', ['label' => 'payments']],
    ]);

    it('refuses issues from another project', function () {
        $foreign = Issue::factory()->create(['assigned_user_id' => null]);

        $this->actingAs($this->morten)
            ->post(route('issues.bulk-assign', $this->project), ['user_id' => $this->morten->id, 'issues' => [$foreign->uuid]])
            ->assertSessionHasErrors('issues.0');

        expect($foreign->refresh()->assigned_user_id)->toBeNull();
    });
});
