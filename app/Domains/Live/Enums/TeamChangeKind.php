<?php

declare(strict_types=1);

namespace App\Domains\Live\Enums;

/**
 * What changed on a team's private channel; each is the event name a page listens for.
 */
enum TeamChangeKind: string
{
    case Members = 'members.changed';
    case Invitations = 'invitations.changed';
    case Tokens = 'tokens.changed';
    case Alerts = 'alerts.changed';
    case Retention = 'retention.changed';
    case Projects = 'projects.changed';
    case Screens = 'screens.changed';
}
