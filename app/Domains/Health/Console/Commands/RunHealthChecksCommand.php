<?php

declare(strict_types=1);

namespace App\Domains\Health\Console\Commands;

use App\Domains\Health\Actions\DispatchDueHealthChecksAction;
use Illuminate\Console\Command;

class RunHealthChecksCommand extends Command
{
    protected $name = 'lumberjack:run-health-checks';

    protected $description = 'Queue the health checks Lumberjack runs whose interval has come';

    public function handle(DispatchDueHealthChecksAction $action): int
    {
        $this->line($action->execute().' health checks queued');

        return self::SUCCESS;
    }
}
