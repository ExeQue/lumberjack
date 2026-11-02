<?php

declare(strict_types=1);

namespace App\Domains\Administration\Enums;

use App\Models\User;

/**
 * Where this Lumberjack stands with its first-run setup.
 */
enum SetupState: string
{
    /** No users at all: setup creates the first administrator. */
    case Fresh = 'fresh';

    /** Users, but no administrator who can sign in. Setup only with LUMBERJACK_SETUP_TOKEN. */
    case Recovery = 'recovery';

    /** An active administrator exists: setup does not exist. */
    case Done = 'done';

    public static function current(): self
    {
        return match (true) {
            User::query()->whereActiveAdmin()->exists() => self::Done,
            User::query()->exists() => self::Recovery,
            default => self::Fresh,
        };
    }

    /**
     * Whether /setup may be shown. Recovery is never open to anyone without the
     * token: otherwise whoever found the page first would own every team.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Fresh => true,
            self::Recovery => (string) config('app.setup_token') !== '',
            self::Done => false,
        };
    }
}
