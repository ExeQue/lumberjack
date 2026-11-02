<?php

declare(strict_types=1);

namespace App\Domains\Teams\Console\Commands;

use App\Domains\Teams\Actions\CreateUserAction;
use App\Models\User;
use Illuminate\Console\Command;
use Override;
use Symfony\Component\Console\Input\InputOption;

/**
 * Lumberjack has no public sign-up: the first owner is created here, everyone
 * else joins by invitation.
 */
class CreateUserCommand extends Command
{
    protected $name = 'lumberjack:create-user';

    protected $description = 'Create a user with a personal team';

    public function handle(CreateUserAction $action): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $email = (string) ($this->option('email') ?: $this->ask('E-mail'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with that e-mail already exists.');

            return self::FAILURE;
        }

        // The first user runs this Lumberjack; later ones are administrators when asked for.
        $first = User::query()->doesntExist();
        $user = $action->execute($name, $email, $password);

        if ($first || $this->option('admin')) {
            $user->forceFill(['is_admin' => true])->save();
            $this->info("{$user->email} is a system administrator.");
        }
        $this->info("Created {$user->email} with team \"{$user->currentTeam?->name}\".");

        return self::SUCCESS;
    }

    /** @return list<InputOption> */
    #[Override]
    protected function getOptions(): array
    {
        return [
            new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Full name'),
            new InputOption('email', null, InputOption::VALUE_REQUIRED, 'E-mail address'),
            new InputOption('password', null, InputOption::VALUE_REQUIRED, 'Password'),
            new InputOption('admin', null, InputOption::VALUE_NONE, 'Make the user a system administrator'),
        ];
    }
}
