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
    case ScreenShared = 'screen_shared';
    case ScreenShareChanged = 'screen_share_changed';
    case ScreenShareRotated = 'screen_share_rotated';
    case ScreenUnshared = 'screen_unshared';
    case DisplayPaired = 'display_paired';
    case DisplayRemoved = 'display_removed';
}
