<?php

declare(strict_types=1);

use App\Domains\Screens\Eloquent\Builders\TvPairingBuilder;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\TvPairing;

covers(TvPairing::class, TvPairingBuilder::class);

describe('a pairing code', function () {
    it('shows as two groups of three and reads back however it was typed', function (string $typed) {
        expect(TvPairing::factory()->make(['code' => 'K7QM2X'])->formattedCode())->toBe('K7Q-M2X')
            ->and(TvPairing::normalizeCode($typed))->toBe('K7QM2X');
    })->with(['as shown' => 'K7Q-M2X', 'lower case' => 'k7qm2x', 'with a space' => ' k7q m2x ']);

    it('is pending until confirmed or expired', function () {
        $pending = TvPairing::factory()->create();
        $expired = TvPairing::factory()->expired()->create();
        $confirmed = TvPairing::factory()->confirmedFor(Display::factory()->create())->create();

        expect($confirmed->display)->toBeInstanceOf(Display::class)
            ->and($pending->isPending())->toBeTrue()
            ->and($expired->isPending())->toBeFalse()
            ->and($confirmed->isPending())->toBeFalse()
            ->and(TvPairing::query()->wherePending()->pluck('id')->all())->toBe([$pending->id])
            ->and(TvPairing::query()->whereExpired()->pluck('id')->all())->toBe([$expired->id])
            ->and(TvPairing::query()->whereKey($expired->id)->orWherePending()->count())->toBe(2)
            ->and(TvPairing::query()->whereKey($pending->id)->orWhereExpired()->count())->toBe(2);
    });

    it('never uses 0, O, 1 or I', function () {
        expect(TvPairing::CODE_ALPHABET)->not->toContain('0')->not->toContain('O')->not->toContain('1')->not->toContain('I')
            ->and(strlen(TvPairing::CODE_ALPHABET))->toBe(32);
    });
});
