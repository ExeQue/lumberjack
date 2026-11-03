<?php

declare(strict_types=1);

namespace App\Domains\Issues\Console\Commands;

use App\Domains\Issues\Actions\SendWatchDigestsAction;
use Illuminate\Console\Command;

class SendWatchDigestsCommand extends Command
{
    protected $name = 'lumberjack:send-watch-digests';

    protected $description = 'Mail watchers the updates on their issues that waited out the grouping window';

    public function handle(SendWatchDigestsAction $action): int
    {
        $this->line($action->execute().' watching mails sent');

        return self::SUCCESS;
    }
}
