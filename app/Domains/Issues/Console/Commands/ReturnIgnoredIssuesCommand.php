<?php

declare(strict_types=1);

namespace App\Domains\Issues\Console\Commands;

use App\Domains\Issues\Actions\ReturnDueIgnoredIssuesAction;
use Illuminate\Console\Command;

class ReturnIgnoredIssuesCommand extends Command
{
    protected $name = 'lumberjack:return-ignored-issues';

    protected $description = 'Re-open the issues that were ignored for a while, once that while is over';

    public function handle(ReturnDueIgnoredIssuesAction $action): int
    {
        $this->line($action->execute().' ignored issues came back');

        return self::SUCCESS;
    }
}
