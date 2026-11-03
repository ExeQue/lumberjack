<?php

declare(strict_types=1);

namespace App\Domains\Administration\Enums;

enum AdminAction: string
{
    case TeamCreated = 'team_created';
    case TeamDeleted = 'team_deleted';
    case TeamOpened = 'team_opened';
    case UserSuspended = 'user_suspended';
    case UserReinstated = 'user_reinstated';
    case AdminGranted = 'admin_granted';
    case AdminRevoked = 'admin_revoked';
    case PasswordResetSent = 'password_reset_sent';
    case PasswordChangeRequired = 'password_change_required';
    case UserInvited = 'user_invited';
    case PublicStatsEnabled = 'public_stats_enabled';
    case PublicStatsRotated = 'public_stats_rotated';
    case PublicStatsChanged = 'public_stats_changed';
    case PublicStatsDisabled = 'public_stats_disabled';
}
