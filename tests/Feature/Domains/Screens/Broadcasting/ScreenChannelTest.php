<?php

declare(strict_types=1);

use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvViewer;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;

covers(ScreenChannel::class, TvViewer::class);

describe('listening to a screen', function () {
    beforeEach(function () {
        $this->screen = Screen::factory()->create();
    });

    it('lets the team\'s members listen and nobody else', function () {
        $member = User::factory()->inTeam($this->screen->team, TeamRole::Member)->create();
        $outsider = User::factory()->inTeam()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        expect(app(ScreenChannel::class)->join($member, $this->screen))->toBeTrue()
            ->and(app(ScreenChannel::class)->join($outsider, $this->screen))->toBeFalse()
            ->and(app(ScreenChannel::class)->join($admin, $this->screen))->toBeTrue();
    });

    it('lets a display listen to the screen it shows, and no other', function () {
        $other = Screen::factory()->for($this->screen->team)->create();
        $viewer = fn (Display ...$displays): TvViewer => new TvViewer(collect($displays), collect());

        expect(app(ScreenChannel::class)->join($viewer(Display::factory()->showing($this->screen)->create()), $this->screen))->toBeTrue()
            ->and(app(ScreenChannel::class)->join($viewer(Display::factory()->showing($other)->create()), $this->screen))->toBeFalse()
            // In turn: any screen of its playlist.
            ->and(app(ScreenChannel::class)->join($viewer(Display::factory()->showing($other)->create(['playlist' => [$other->id, $this->screen->id]])), $this->screen))->toBeTrue()
            ->and(app(ScreenChannel::class)->join(new TvViewer(collect(), collect([TvPairing::factory()->create()])), $this->screen))->toBeFalse();
    });

    it('is registered on screen.{uuid}, bound to the screen, for users and displays', function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $channels = (fn (): array => $this->channels)->call($broadcaster);
        $options = (fn (): array => $this->channelOptions)->call($broadcaster);

        expect($channels)->toHaveKey('screen.{screen}')
            ->and($channels['screen.{screen}'])->toBe(ScreenChannel::class)
            ->and($options['screen.{screen}'])->toBe(['guards' => ['web', 'tv']])
            ->and(ScreenChannel::for($this->screen)->name)->toBe('private-screen.'.$this->screen->uuid);
    });
});
