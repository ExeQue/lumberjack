<?php

declare(strict_types=1);

use App\Domains\Screens\Broadcasting\TeamDisplaysChannel;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Support\TvViewer;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;

covers(TeamDisplaysChannel::class);

describe('the team\'s displays channel', function () {
    it('lets the team\'s members listen, and nobody else', function () {
        $team = Team::factory()->create();
        $channel = app(TeamDisplaysChannel::class);

        expect(TeamDisplaysChannel::name($team))->toBe('team.'.$team->uuid.'.displays')
            ->and($channel->join(User::factory()->inTeam($team, TeamRole::Member)->create(), $team->uuid))->toBeTrue()
            ->and($channel->join(User::factory()->inTeam()->create(), $team->uuid))->toBeFalse()
            ->and($channel->join(User::factory()->inTeam($team, TeamRole::Member)->create(), 'not-a-team'))->toBeFalse();
    });

    it('is for signed in users, not TVs', function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $options = (fn (): array => $this->channelOptions)->call($broadcaster);
        $display = Display::factory()->create();

        expect($options['team.{teamUuid}.displays'] ?? [])->toBe([])
            // The tv guard's viewer is no user, so the typed join refuses it outright.
            ->and(fn () => app(TeamDisplaysChannel::class)->join(new TvViewer(collect([$display]), collect()), $display->team->uuid))->toThrow(TypeError::class);
    });
});
