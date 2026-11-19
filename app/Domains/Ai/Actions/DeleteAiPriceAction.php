<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Models\AiPrice;

/**
 * Removes a price. Cost is never stored, so the usage it priced is priced by the next
 * price that applies, or not at all.
 */
class DeleteAiPriceAction
{
    public function execute(AiPrice $price): void
    {
        $price->delete();
    }
}
