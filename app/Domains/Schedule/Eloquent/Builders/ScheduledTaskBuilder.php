<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Eloquent\Builders;

use App\Domains\Schedule\Enums\ScheduledRunStatus;
use App\Domains\Schedule\Models\ScheduledTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<ScheduledTask>
 */
class ScheduledTaskBuilder extends Builder
{
    public function whereNeedsAttention(): self
    {
        return $this->whereIn('last_status', [ScheduledRunStatus::Failed->value, ScheduledRunStatus::Missed->value]);
    }

    public function orWhereNeedsAttention(): self
    {
        return $this->orWhereIn('last_status', [ScheduledRunStatus::Failed->value, ScheduledRunStatus::Missed->value]);
    }
}
