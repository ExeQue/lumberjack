<?php

declare(strict_types=1);

use App\Domains\Live\Actions\BroadcastTeamChangeAction;
use App\Domains\Live\Enums\TeamChangeKind;
use App\Domains\Live\Events\TeamChanged;
use App\Domains\Teams\Models\Team;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Tests\Support\Live\ChecksLiveBroadcasts;

uses(ChecksLiveBroadcasts::class);

covers(BroadcastTeamChangeAction::class, TeamChanged::class);

describe('team broadcasts', function () {
    it('tells the team\'s private channel what changed, without data', function () {
        $team = Team::factory()->create();
        $this->listenForBroadcasts();

        app(BroadcastTeamChangeAction::class)->execute($team->id, TeamChangeKind::Members);
        app(DeferredCallbackCollection::class)->invoke();

        Event::assertDispatched(TeamChanged::class, fn (TeamChanged $event): bool => $event->broadcastOn()->name === 'private-team.'.$team->uuid
            && $event->broadcastAs() === 'members.changed'
            && $event->broadcastWith() === ['kind' => 'members.changed']);
    });

    it('sends one broadcast per team and kind however many rows a request changed', function () {
        $team = Team::factory()->create();
        $this->listenForBroadcasts();
        $action = app(BroadcastTeamChangeAction::class);

        foreach (range(1, 4) as $_) {
            $action->execute($team->id, TeamChangeKind::Retention);
        }

        $action->execute($team->id, TeamChangeKind::Alerts);
        app(DeferredCallbackCollection::class)->invoke();

        Event::assertDispatchedTimes(TeamChanged::class, 2);
    });

    it('tells every team when an application-wide setting changed', function () {
        Team::factory()->count(3)->create();
        $this->listenForBroadcasts();

        app(BroadcastTeamChangeAction::class)->everyTeam(TeamChangeKind::Retention);
        app(DeferredCallbackCollection::class)->invoke();

        Event::assertDispatchedTimes(TeamChanged::class, 3);
    });

    it('sends nothing until the request is done', function () {
        $team = Team::factory()->create();
        $this->listenForBroadcasts();

        app(BroadcastTeamChangeAction::class)->execute($team->id, TeamChangeKind::Members);

        Event::assertNotDispatched(TeamChanged::class);
    });
});
