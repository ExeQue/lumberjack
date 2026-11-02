<?php

declare(strict_types=1);

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Http\Controllers\IssueActionController;
use App\Domains\Issues\Models\Issue;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Tests\Support\Teams\CreatesTeamMembers;

covers(IssueActionController::class);

uses(CreatesTeamMembers::class);

describe('what a member may do to an issue', function () {
    beforeEach(function () {
        ['user' => $this->user, 'project' => $this->project] = $this->memberWithProject(TeamRole::Member);
        $this->issue = Issue::factory()->for($this->project)->create();
    });

    it('resolves an issue', function () {
        $this->actingAs($this->user)
            ->put(route('issues.status', [$this->project, $this->issue]), ['status' => 'resolved'])
            ->assertRedirect();

        expect($this->issue->refresh()->status)->toBe(IssueStatus::Resolved);
    });

    it('resolves several issues at once', function () {
        $other = Issue::factory()->for($this->project)->create();

        $this->actingAs($this->user)
            ->post(route('issues.bulk-status', $this->project), ['status' => 'ignored', 'issues' => [$this->issue->uuid, $other->uuid]]);

        expect(Issue::query()->whereStatus(IssueStatus::Ignored)->count())->toBe(2);
    });

    it('assigns a team member and adds a note', function () {
        $this->actingAs($this->user)->put(route('issues.assign', [$this->project, $this->issue]), ['user_id' => $this->user->id]);
        $this->actingAs($this->user)->post(route('issues.notes.store', [$this->project, $this->issue]), ['body' => 'Looking into it']);

        expect($this->issue->refresh()->assigned_user_id)->toBe($this->user->id)
            ->and($this->issue->notes()->sole()->user_id)->toBe($this->user->id);
    });

    it('refuses an assignee from outside the team', function () {
        $stranger = User::factory()->create();

        $this->actingAs($this->user)->put(route('issues.assign', [$this->project, $this->issue]), ['user_id' => $stranger->id])->assertSessionHasErrors('user_id');

        expect($this->issue->refresh()->assigned_user_id)->toBeNull();
    });
});

describe('who may not', function () {
    it('forbids someone outside the team', function () {
        ['project' => $project] = $this->memberWithProject();
        $issue = Issue::factory()->for($project)->create();

        $this->actingAs(User::factory()->inTeam()->create())
            ->put(route('issues.status', [$project, $issue]), ['status' => 'resolved'])
            ->assertForbidden();
    });

    it('does not reach an issue through another project', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $foreign = Issue::factory()->create();

        $this->actingAs($user)->put(route('issues.status', [$project, $foreign]), ['status' => 'resolved'])->assertNotFound();
    });
});
