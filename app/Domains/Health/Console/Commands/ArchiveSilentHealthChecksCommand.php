<?php

declare(strict_types=1);

namespace App\Domains\Health\Console\Commands;

use App\Domains\Health\Actions\ArchiveSilentHealthChecksAction;
use Illuminate\Console\Command;

class ArchiveSilentHealthChecksCommand extends Command
{
    protected $name = 'lumberjack:archive-silent-health-checks';

    protected $description = 'Archive reported health checks that have sent nothing for a week';

    public function handle(ArchiveSilentHealthChecksAction $action): int
    {
        $this->line($action->execute().' health checks archived');

        return self::SUCCESS;
    }
}
