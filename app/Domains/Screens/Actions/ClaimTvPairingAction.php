<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\TvPairing;
use App\Domains\Screens\Support\TvCredentials;
use Illuminate\Support\Facades\DB;

class ClaimTvPairingAction
{
    /**
     * The display token for the TV whose code was confirmed, once: the pairing is gone
     * afterwards. Only its hash is kept. Null while the code is not confirmed, or expired.
     */
    public function execute(TvPairing $pairing): ?string
    {
        return DB::transaction(function () use ($pairing): ?string {
            $locked = TvPairing::query()->whereKey($pairing->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            $display = $locked?->display;

            if ($locked === null || $display === null || $locked->expires_at->isPast()) {
                return null;
            }

            $token = TvCredentials::newDisplayToken();
            $display->update(['token_hash' => TvCredentials::hash($token), 'last_seen_at' => now()]);
            $locked->delete();

            return $token;
        });
    }
}
