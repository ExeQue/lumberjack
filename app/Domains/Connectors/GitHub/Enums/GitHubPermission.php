<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Enums;

/**
 * The read permissions a token may have beyond the ones connecting requires. Each unlocks
 * sources; one the token lacks only leaves them out. The value is GitHub's own name for
 * the permission, as its token form takes it from the address.
 */
enum GitHubPermission: string
{
    case Issues = 'issues';
    case DependabotAlerts = 'vulnerability_alerts';
    case CodeScanningAlerts = 'security_events';
    case SecretScanningAlerts = 'secret_scanning_alerts';

    /** As GitHub's token form names it, such as "Dependabot alerts". */
    public function label(): string
    {
        return __('connectors.github.permissions.'.$this->value);
    }

    /** What the permission adds, in a few words. */
    public function unlocks(): string
    {
        return __('connectors.github.permissions.unlocks.'.$this->value);
    }

    /** The path under the repository it reads, open items only. */
    public function path(): string
    {
        return match ($this) {
            self::Issues => '/issues',
            self::DependabotAlerts => '/dependabot/alerts',
            self::CodeScanningAlerts => '/code-scanning/alerts',
            self::SecretScanningAlerts => '/secret-scanning/alerts',
        };
    }
}
