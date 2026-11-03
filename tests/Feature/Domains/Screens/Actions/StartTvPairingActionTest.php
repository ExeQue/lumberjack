<?php

declare(strict_types=1);

use App\Domains\Screens\Actions\StartTvPairingAction;
use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvCredentials;

covers(StartTvPairingAction::class);

describe('starting a pairing', function () {
    it('keeps only the hash of the secret and what the TV is', function () {
        ['pairing' => $pairing, 'secret' => $secret] = app(StartTvPairingAction::class)->execute(str_repeat('x', 600), '10.0.0.9');

        expect($pairing->secret_hash)->toBe(TvCredentials::hash($secret))
            ->and($pairing->secret_hash)->not->toBe($secret)
            ->and(mb_strlen((string) $pairing->user_agent))->toBe(500)
            ->and($pairing->ip)->toBe('10.0.0.9');
    });

    it('never hands out a code in use', function () {
        $taken = TvPairing::factory()->create();
        $codes = collect(range(1, 20))->map(fn () => app(StartTvPairingAction::class)->execute(null, null)['pairing']->code);

        expect($codes->push($taken->code)->unique()->count())->toBe(21);
    });
});
