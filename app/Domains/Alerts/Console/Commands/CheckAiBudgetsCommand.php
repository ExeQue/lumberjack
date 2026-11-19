<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console\Commands;

use App\Domains\Alerts\Actions\CheckAiBudgetsAction;
use Illuminate\Console\Command;

class CheckAiBudgetsCommand extends Command
{
    protected $name = 'lumberjack:check-ai-budgets';

    protected $description = 'Alert on AI budgets passing their thresholds, or on course to go over';

    public function handle(CheckAiBudgetsAction $action): int
    {
        $this->line($action->execute().' AI budget alerts queued');

        return self::SUCCESS;
    }
}
