<?php

declare(strict_types=1);

namespace App\Domains\Shared\Actions\Concerns;

/**
 * For an Action that can refuse for a reason the user can fix: addReason() records why
 * and returns false, and the caller reads $reasons to explain it.
 */
trait CanHaveReasons
{
    /** @var list<string> */
    public array $reasons = [];

    protected function addReason(string $reason): false
    {
        $this->reasons[] = $reason;

        return false;
    }
}
