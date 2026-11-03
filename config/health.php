<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Health checks Lumberjack runs itself
    |--------------------------------------------------------------------------
    |
    | HTTP, SSL and TCP checks refuse hosts that resolve to private, loopback,
    | link-local or reserved addresses, so a check cannot reach this server or
    | the network behind it. Self-hosters who want to watch internal services
    | can turn that off.
    |
    */

    'allow_private' => (bool) env('LUMBERJACK_HEALTH_ALLOW_PRIVATE', false),

    // At most one "down" or "degraded" alert per check in this many minutes, so a flapping check does not spam.
    'alert_throttle_minutes' => (int) env('LUMBERJACK_HEALTH_ALERT_THROTTLE', 30),

    // The low-priority queue the checks run on; the background workers take it after alerts.
    'queue' => 'health',

];
