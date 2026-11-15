<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console\Commands;

use App\Domains\Administration\Actions\RecordSystemSampleAction;
use Illuminate\Console\Command;

class SampleSystemCommand extends Command
{
    protected $name = 'lumberjack:sample-system';

    protected $description = 'Record the CPU, memory and disk of the server Lumberjack runs on';

    public function handle(RecordSystemSampleAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
