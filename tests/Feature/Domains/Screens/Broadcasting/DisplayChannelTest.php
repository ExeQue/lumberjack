<?php

declare(strict_types=1);

use App\Domains\Screens\Broadcasting\DisplayChannel;
use App\Domains\Screens\Broadcasting\TvPairingChannel;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvViewer;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;

covers(DisplayChannel::class, TvPairingChannel::class, TvViewer::class);

describe('the channels of a TV', function () {
    it('lets a display hear about itself only', function () {
        $display = Display::factory()->create();

        expect(app(DisplayChannel::class)->join(new TvViewer(collect([Display::factory()->create(), $display]), collect()), $display))->toBeTrue()
            ->and(app(DisplayChannel::class)->join(new TvViewer(collect([Display::factory()->create()]), collect()), $display))->toBeFalse()
            ->and(app(DisplayChannel::class)->join(User::factory()->create(), $display))->toBeFalse()
            ->and(DisplayChannel::for($display)->name)->toBe('private-display.'.$display->uuid);
    });

    it('lets the TV holding a pairing hear that it was confirmed, on a channel without the code', function () {
        $pairing = TvPairing::factory()->create();

        expect(app(TvPairingChannel::class)->join(new TvViewer(collect(), collect([$pairing])), $pairing->uuid))->toBeTrue()
            ->and(app(TvPairingChannel::class)->join(new TvViewer(collect(), collect([TvPairing::factory()->create()])), $pairing->uuid))->toBeFalse()
            ->and(app(TvPairingChannel::class)->join(User::factory()->create(), $pairing->uuid))->toBeFalse()
            ->and(TvPairingChannel::for($pairing)->name)->toBe('private-tv-pairing.'.$pairing->uuid)
            ->and(TvPairingChannel::name($pairing))->not->toContain($pairing->code);
    });

    it('are only for the tv guard', function () {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $options = (fn (): array => $this->channelOptions)->call($broadcaster);

        expect($options['display.{display}'])->toBe(['guards' => ['tv']])
            ->and($options['tv-pairing.{uuid}'])->toBe(['guards' => ['tv']]);
    });
});
