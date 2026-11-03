<?php

declare(strict_types=1);

use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Models\Screen;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;

covers(ScreenChannel::class);

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

    it('is registered on screen.{uuid}, bound to the screen', function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $channels = (fn (): array => $this->channels)->call($broadcaster);

        expect($channels)->toHaveKey('screen.{screen}')
            ->and($channels['screen.{screen}'])->toBe(ScreenChannel::class)
            ->and(ScreenChannel::for($this->screen)->name)->toBe('private-screen.'.$this->screen->uuid);
    });
});
