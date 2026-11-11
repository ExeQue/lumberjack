<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console\Commands;

use App\Domains\Alerts\Actions\SendWeeklySummariesAction;
use Illuminate\Console\Command;

class SendWeeklySummariesCommand extends Command
{
    protected $name = 'lumberjack:send-weekly-summaries';

    protected $description = 'Send the weekly summaries that are due';

    public function handle(SendWeeklySummariesAction $action): int
    {
        $this->line($action->execute().' weekly summaries queued');

        return self::SUCCESS;
    }
}
