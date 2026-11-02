<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Console\Commands;

use App\Domains\Schedule\Actions\MarkMissedScheduledTasksAction;
use Illuminate\Console\Command;

class MarkMissedScheduledTasksCommand extends Command
{
    protected $name = 'lumberjack:mark-missed-tasks';

    protected $description = 'Flag scheduled tasks whose run is overdue as missed';

    public function handle(MarkMissedScheduledTasksAction $action): int
    {
        $this->line($action->execute().' scheduled runs marked missed');

        return self::SUCCESS;
    }
}
