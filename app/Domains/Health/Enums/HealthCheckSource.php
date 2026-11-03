<?php

declare(strict_types=1);

namespace App\Domains\Health\Enums;

enum HealthCheckSource: string
{
    /** Run by the app's own scheduler and sent through ingest. */
    case Reported = 'reported';

    /** Defined in the UI and run by Lumberjack. */
    case Monitored = 'monitored';
}
