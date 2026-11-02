<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Console\Commands;

use App\Domains\Jobs\Actions\MarkStuckJobsAction;
use Illuminate\Console\Command;

class MarkStuckJobsCommand extends Command
{
    protected $name = 'lumberjack:mark-stuck-jobs';

    protected $description = 'Mark job runs that outlived their timeout as stuck';

    public function handle(MarkStuckJobsAction $action): int
    {
        $this->line($action->execute().' runs marked stuck');

        return self::SUCCESS;
    }
}
