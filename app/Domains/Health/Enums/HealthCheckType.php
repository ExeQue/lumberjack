<?php

declare(strict_types=1);

namespace App\Domains\Health\Enums;

/**
 * What a check Lumberjack runs itself does.
 */
enum HealthCheckType: string
{
    case Http = 'http';
    case Ssl = 'ssl';
    case Tcp = 'tcp';
    case Dns = 'dns';
    case Heartbeat = 'heartbeat';

    /**
     * Types that open a connection to the host, so they go through the private address guard.
     */
    public function connects(): bool
    {
        return in_array($this, [self::Http, self::Ssl, self::Tcp], true);
    }
}
