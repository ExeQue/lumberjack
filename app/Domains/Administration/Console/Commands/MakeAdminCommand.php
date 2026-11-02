<?php

declare(strict_types=1);

namespace App\Domains\Administration\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Grants or takes system administration from the command line, e.g. when no
 * administrator can sign in any more.
 */
class MakeAdminCommand extends Command
{
    protected $name = 'lumberjack:make-admin';

    protected $description = 'Make a user a system administrator of this Lumberjack';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user with that e-mail address.');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');

        if ($revoke && User::query()->whereActiveAdmin()->whereKeyNot($user->id)->doesntExist()) {
            $this->components->error(__('administration.last_admin'));

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $revoke])->save();
        $this->components->info($revoke ? "{$user->email} is no longer a system administrator." : "{$user->email} is a system administrator.");

        return self::SUCCESS;
    }

    /** @return list<InputArgument> */
    protected function getArguments(): array
    {
        return [new InputArgument('email', InputArgument::REQUIRED, 'The user\'s e-mail address')];
    }

    /** @return list<InputOption> */
    protected function getOptions(): array
    {
        return [new InputOption('revoke', null, InputOption::VALUE_NONE, 'Take system administration away instead')];
    }
}
