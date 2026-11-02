<?php

declare(strict_types=1);

namespace App\Domains\Commands\Enums;

enum CommandRunStatus: string
{
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
}
