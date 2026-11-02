<?php

declare(strict_types=1);

namespace App\Domains\Schedule\Enums;

enum ScheduledRunStatus: string
{
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Missed = 'missed';

    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::Missed;
    }
}
