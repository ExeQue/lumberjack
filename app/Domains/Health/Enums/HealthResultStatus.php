<?php

declare(strict_types=1);

namespace App\Domains\Health\Enums;

/**
 * The outcome of one run of a check.
 */
enum HealthResultStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failed = 'failed';
}
