<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Eloquent\Builders;

use App\Domains\Jobs\Enums\JobRunStatus;
use App\Domains\Jobs\Models\JobRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<JobRun>
 */
class JobRunBuilder extends Builder
{
    public function whereStatus(JobRunStatus ...$statuses): self
    {
        return $this->whereIn('status', array_map(fn (JobRunStatus $s): string => $s->value, $statuses));
    }

    public function orWhereStatus(JobRunStatus ...$statuses): self
    {
        return $this->orWhereIn('status', array_map(fn (JobRunStatus $s): string => $s->value, $statuses));
    }
}
