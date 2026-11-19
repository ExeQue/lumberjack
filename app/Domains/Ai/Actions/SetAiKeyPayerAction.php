<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Models\AiKey;

/**
 * Says who pays for a key's tokens, or (null) lets it inherit again; see AiKey::effectivePayer().
 * It counts for all of the key's usage, before the change too.
 */
class SetAiKeyPayerAction
{
    public function execute(AiKey $key, ?AiPayer $payer): void
    {
        $key->update(['payer' => $payer]);
    }
}
