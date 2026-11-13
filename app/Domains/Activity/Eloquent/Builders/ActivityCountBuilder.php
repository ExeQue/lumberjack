<?php

declare(strict_types=1);

namespace App\Domains\Activity\Eloquent\Builders;

use App\Domains\Activity\Models\ActivityCount;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<ActivityCount>
 */
class ActivityCountBuilder extends Builder
{
    public function whereEvent(string $group, string $name): self
    {
        return $this->where(fn (self $q) => $q->where('group', $group)->where('name', $name));
    }

    public function orWhereEvent(string $group, string $name): self
    {
        return $this->orWhere(fn (self $q) => $q->where('group', $group)->where('name', $name));
    }
}
