<?php

declare(strict_types=1);

namespace App\Domains\Jobs\Enums;

enum JobRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Released = 'released';
    case Failed = 'failed';
    case Stuck = 'stuck';

    public function label(): string
    {
        return __('jobs.statuses.'.$this->value);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Processed, self::Released, self::Failed], true);
    }
}
