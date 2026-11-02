<?php

declare(strict_types=1);

namespace App\Domains\Administration\Eloquent\Builders;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<User>
 */
class UserBuilder extends Builder
{
    public function whereSuspended(): self
    {
        return $this->whereNotNull('suspended_at');
    }

    public function orWhereSuspended(): self
    {
        return $this->orWhereNotNull('suspended_at');
    }

    public function whereNotSuspended(): self
    {
        return $this->whereNull('suspended_at');
    }

    public function orWhereNotSuspended(): self
    {
        return $this->orWhereNull('suspended_at');
    }

    /**
     * Administrators who can act: a suspended administrator cannot sign in.
     */
    public function whereActiveAdmin(): self
    {
        return $this->where(fn (self $q) => $q->where('is_admin', true)->whereNotSuspended());
    }

    public function orWhereActiveAdmin(): self
    {
        return $this->orWhere(fn (self $q) => $q->where('is_admin', true)->whereNotSuspended());
    }
}
