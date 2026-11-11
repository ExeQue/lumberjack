<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Console\Commands;

use App\Domains\Maintenance\Actions\CheckMaintenanceOverrunsAction;
use Illuminate\Console\Command;

class CheckMaintenanceOverrunsCommand extends Command
{
    protected $name = 'lumberjack:check-maintenance-overruns';

    protected $description = 'Alert on maintenance windows still open past their expected end';

    public function handle(CheckMaintenanceOverrunsAction $action): int
    {
        $this->line($action->execute().' maintenance overrun alerts queued');

        return self::SUCCESS;
    }
}
