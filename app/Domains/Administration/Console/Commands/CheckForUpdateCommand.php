<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console\Commands;

use App\Domains\Administration\Actions\CheckForUpdateAction;
use Illuminate\Console\Command;

class CheckForUpdateCommand extends Command
{
    protected $name = 'lumberjack:check-for-update';

    protected $description = 'Ask GitHub whether a newer release is out';

    public function handle(CheckForUpdateAction $action): int
    {
        return $action->execute() ? self::SUCCESS : self::FAILURE;
    }
}
