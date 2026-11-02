<?php

declare(strict_types=1);

use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Events\NoteMentioned;
use App\Domains\Issues\Listeners\NotifyMentionedMembers;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Notifications\MentionedInNoteNotification;
use App\Domains\Issues\Support\NoteMentions;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

covers(NotifyMentionedMembers::class, NoteMentions::class);

beforeEach(function () {
    Notification::fake();
    Event::fake([NoteMentioned::class]);
    $this->issue = Issue::factory()->create();
    $team = $this->issue->project->team;
    $this->author = User::factory()->inTeam($team)->create(['name' => 'Morten']);
    $this->tagged = User::factory()->inTeam($team)->create(['name' => 'Sofie']);
    $this->outsider = User::factory()->create(['name' => 'Eve']);
});

describe('tagging members in a note', function () {
    it('mails and toasts the tagged team members, with the tags written as names', function () {
        app(AddIssueNoteAction::class)->execute($this->issue, "<@{$this->tagged->id}> can you check the DB? cc <@{$this->author->id}> <@{$this->outsider->id}>", $this->author);

        Notification::assertSentTo($this->tagged, MentionedInNoteNotification::class, fn ($n) => str_contains($n->body, '@Sofie can you check the DB? cc @Morten')
            && str_contains($n->body, "<@{$this->outsider->id}>"));
        Notification::assertNotSentTo([$this->author, $this->outsider], MentionedInNoteNotification::class);
        Event::assertDispatched(NoteMentioned::class, fn (NoteMentioned $e) => $e->broadcastOn()->name === 'private-App.Models.User.'.$this->tagged->id);
    });

    it('does nothing for a note without tags', function () {
        app(AddIssueNoteAction::class)->execute($this->issue, 'Just a note.', $this->author);

        Notification::assertNothingSent();
        Event::assertNotDispatched(NoteMentioned::class);
    });
});
