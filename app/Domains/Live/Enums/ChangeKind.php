<?php

declare(strict_types=1);

namespace App\Domains\Live\Enums;

enum ChangeKind: string
{
    case Issues = 'issues.changed';
    case Jobs = 'jobs.changed';
    case Schedule = 'schedule.changed';
    case Commands = 'commands.changed';
    case Logs = 'logs.changed';
    case Performance = 'performance.changed';
    case Agents = 'agents.changed';
}
