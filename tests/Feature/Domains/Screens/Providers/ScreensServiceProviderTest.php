<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Providers\ScreensServiceProvider;
use App\Domains\Screens\Support\TvCredentials;
use App\Domains\Screens\Support\TvViewer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

covers(ScreensServiceProvider::class, TvViewer::class, TvCredentials::class);

describe('the tv guard', function () {
    beforeEach(function () {
        $this->viewer = function (array $cookies): mixed {
            Auth::forgetGuards();
            app()->instance('request', Request::create('/broadcasting/auth', 'POST', cookies: $cookies));

            return Auth::guard('tv')->user();
        };
    });

    it('finds the displays and pairings of every window of the browser', function () {
        $one = Display::factory()->withToken('ljd_one')->create();
        $two = Display::factory()->withToken('ljd_two')->create(['slot' => 2]);
        $pairing = TvPairing::factory()->withSecret('secret')->create(['slot' => 3]);

        $viewer = ($this->viewer)([
            TvCredentials::displayCookieName(1) => 'ljd_one',
            TvCredentials::displayCookieName(2) => 'ljd_two',
            TvCredentials::pairingCookieName(3) => 'secret',
        ]);

        expect($viewer)->toBeInstanceOf(TvViewer::class)
            ->and($viewer->isDisplay($one))->toBeTrue()
            ->and($viewer->isDisplay($two))->toBeTrue()
            ->and($viewer->holdsPairing($pairing->uuid))->toBeTrue()
            ->and($viewer->seesTeam($one->team_id))->toBeTrue()
            ->and($viewer->seesTeam($pairing->id + 1000))->toBeFalse()
            ->and(($this->viewer)([TvCredentials::pairingCookieName(1) => 'secret'])?->displays)->toHaveCount(0)
            ->and(($this->viewer)([TvCredentials::displayCookieName(1) => 'ljd_other', TvCredentials::pairingCookieName(1) => 'other']))->toBeNull()
            ->and(($this->viewer)([]))->toBeNull();
    });
});
