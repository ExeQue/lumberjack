<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console\Commands;

use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use Illuminate\Console\Command;

class CheckQueueBacklogsCommand extends Command
{
    protected $name = 'lumberjack:check-queue-backlogs';

    protected $description = 'Alert on queues over their backlog thresholds, and on those back under';

    public function handle(CheckQueueBacklogsAction $action): int
    {
        $this->line($action->execute().' queue backlog alerts queued');

        return self::SUCCESS;
    }
}
