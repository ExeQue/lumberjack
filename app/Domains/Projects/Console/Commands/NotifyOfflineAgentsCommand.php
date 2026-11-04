<?php

declare(strict_types=1);

namespace App\Domains\Projects\Console\Commands;

use App\Domains\Projects\Actions\NotifyOfflineAgentsAction;
use Illuminate\Console\Command;

class NotifyOfflineAgentsCommand extends Command
{
    protected $name = 'lumberjack:notify-offline-agents';

    protected $description = 'Tell members who want it when an agent of their project goes offline';

    public function handle(NotifyOfflineAgentsAction $action): int
    {
        $this->line($action->execute().' projects told of offline agents');

        return self::SUCCESS;
    }
}
