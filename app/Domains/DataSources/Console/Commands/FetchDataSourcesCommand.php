<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Console\Commands;

use App\Domains\DataSources\Actions\DispatchDueDataSourceConnectionsAction;
use App\Domains\DataSources\Actions\DispatchDueDataSourcesAction;
use App\Domains\DataSources\Actions\DispatchDueDataSourceWindowsAction;
use Illuminate\Console\Command;

class FetchDataSourcesCommand extends Command
{
    protected $name = 'lumberjack:fetch-data-sources';

    protected $description = 'Queue the external JSON data sources whose interval has come';

    public function handle(DispatchDueDataSourcesAction $action, DispatchDueDataSourceWindowsAction $windows, DispatchDueDataSourceConnectionsAction $connections): int
    {
        $this->line($action->execute().' data sources queued, '.$windows->execute().' pulls queued, '.$connections->execute().' apps asked');

        return self::SUCCESS;
    }
}
