<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvCredentials;

class StartTvPairingAction
{
    /**
     * A new code for a TV's window, bound to the secret its pairing cookie will carry. Expired
     * pairings go on the way, so the codes in use stay few.
     *
     * @return array{pairing: TvPairing, secret: string}
     */
    public function execute(?string $userAgent, ?string $ip, int $slot = 1, ?string $deviceHash = null): array
    {
        TvPairing::query()->whereExpired()->delete();

        do {
            $code = TvCredentials::newCode();
        } while (TvPairing::query()->where('code', $code)->exists());

        $secret = TvCredentials::newSecret();
        $pairing = TvPairing::query()->create([
            'code' => $code,
            'secret_hash' => TvCredentials::hash($secret),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'ip' => $ip,
            'slot' => $slot,
            'device_hash' => $deviceHash,
            'expires_at' => now()->addMinutes(TvPairing::LIFETIME_MINUTES),
        ]);

        return ['pairing' => $pairing, 'secret' => $secret];
    }
}
