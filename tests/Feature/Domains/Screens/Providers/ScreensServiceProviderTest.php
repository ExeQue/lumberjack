<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Providers\ScreensServiceProvider;
use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

covers(ScreensServiceProvider::class);

describe('the tv guard', function () {
    beforeEach(function () {
        $this->viewer = function (array $cookies): mixed {
            Auth::forgetGuards();
            app()->instance('request', Request::create('/broadcasting/auth', 'POST', cookies: $cookies));

            return Auth::guard('tv')->user();
        };
    });

    it('finds a display by its token, else a TV by its pairing cookie', function () {
        $display = Display::factory()->withToken('ljd_token')->create();
        $pairing = TvPairing::factory()->withSecret('secret')->create();

        expect(($this->viewer)([TvCredentials::DISPLAY_COOKIE => 'ljd_token', TvCredentials::PAIRING_COOKIE => 'secret']))->toBeInstanceOf(Display::class)
            ->and(($this->viewer)([TvCredentials::DISPLAY_COOKIE => 'ljd_token'])?->is($display))->toBeTrue()
            ->and(($this->viewer)([TvCredentials::PAIRING_COOKIE => 'secret'])?->is($pairing))->toBeTrue()
            ->and(($this->viewer)([TvCredentials::DISPLAY_COOKIE => 'ljd_other', TvCredentials::PAIRING_COOKIE => 'other']))->toBeNull()
            ->and(($this->viewer)([]))->toBeNull();
    });
});
