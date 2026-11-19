<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Models\AiKey;

/**
 * Names a key in Lumberjack, or (null) takes its name away so its label shows again. The
 * label the app reports is left as it is.
 */
class SetAiKeyNameAction
{
    public function execute(AiKey $key, ?string $name): void
    {
        $name = $name !== null ? trim($name) : null;

        $key->update(['name' => $name !== '' ? $name : null]);
    }
}
