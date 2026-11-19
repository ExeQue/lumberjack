<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Projects\Models\Project;

/**
 * Who pays for the project's keys that neither say so themselves nor have an owner.
 */
class SetProjectAiPayerAction
{
    public function execute(Project $project, AiPayer $payer): void
    {
        $project->update(['ai_default_payer' => $payer]);
    }
}
