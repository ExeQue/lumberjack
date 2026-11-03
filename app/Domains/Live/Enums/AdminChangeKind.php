<?php

declare(strict_types=1);

namespace App\Domains\Live\Enums;

/**
 * What changed on the system administrators' channel.
 */
enum AdminChangeKind: string
{
    case Teams = 'teams.changed';
    case Users = 'users.changed';
    case Audit = 'audit.changed';
    case Retention = 'retention.changed';
}
