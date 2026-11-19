<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console\Commands;

use App\Domains\Alerts\Actions\CheckAiErrorRatesAction;
use Illuminate\Console\Command;

class CheckAiErrorRatesCommand extends Command
{
    protected $name = 'lumberjack:check-ai-error-rates';

    protected $description = 'Alert on AI keys whose calls fail too often, and on those working again';

    public function handle(CheckAiErrorRatesAction $action): int
    {
        $this->line($action->execute().' AI error rate alerts queued');

        return self::SUCCESS;
    }
}
