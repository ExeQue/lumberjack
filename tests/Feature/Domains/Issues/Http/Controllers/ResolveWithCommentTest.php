<?php

declare(strict_types=1);

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Http\Controllers\IssueActionController;
use App\Domains\Issues\Models\Issue;
use App\Models\User;

covers(IssueActionController::class);

describe('resolving with a comment', function () {
    beforeEach(function () {
        $this->issue = Issue::factory()->create();
        $this->user = User::factory()->inTeam($this->issue->project->team)->create();
    });

    it('resolves the issue and keeps the comment as a note by the person who resolved it', function () {
        $this->actingAs($this->user)
            ->put(route('issues.status', [$this->issue->project, $this->issue]), ['status' => 'resolved', 'note' => 'Fixed by retrying the webhook in #482.'])
            ->assertSessionHasNoErrors();

        $note = $this->issue->notes()->sole();
        expect($this->issue->refresh()->status)->toBe(IssueStatus::Resolved)
            ->and($note->body)->toBe('Fixed by retrying the webhook in #482.')
            ->and($note->user_id)->toBe($this->user->id);
    });

    it('adds no note without a comment', function () {
        $this->actingAs($this->user)
            ->put(route('issues.status', [$this->issue->project, $this->issue]), ['status' => 'ignored', 'note' => null])
            ->assertSessionHasNoErrors();

        expect($this->issue->refresh()->status)->toBe(IssueStatus::Ignored)
            ->and($this->issue->notes()->count())->toBe(0);
    });
});
