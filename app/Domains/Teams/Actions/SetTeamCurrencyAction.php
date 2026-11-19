<?php

declare(strict_types=1);

namespace App\Domains\Teams\Actions;

use App\Domains\Teams\Models\Team;

/**
 * The currency the team's AI prices, and so its AI cost, are in. Prices are not converted:
 * they are read in the new currency from then on.
 */
class SetTeamCurrencyAction
{
    public function execute(Team $team, string $currency): void
    {
        $team->update(['currency' => strtoupper($currency)]);
    }
}
