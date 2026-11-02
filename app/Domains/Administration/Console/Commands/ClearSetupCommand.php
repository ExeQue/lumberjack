<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console\Commands;

use App\Domains\Administration\Http\Middleware\RedirectToSetup;
use Illuminate\Console\Command;

/**
 * Forgets that setup is done. Only the flag goes: while users exist, the next
 * request finds them and writes it again; setup only shows on an empty database.
 */
class ClearSetupCommand extends Command
{
    protected $name = 'setup:clear';

    protected $description = 'Delete the "setup is done" flag (storage/app/setup/complete)';

    public function handle(): int
    {
        RedirectToSetup::forget();
        $this->components->info('Setup flag cleared.');

        return self::SUCCESS;
    }
}
