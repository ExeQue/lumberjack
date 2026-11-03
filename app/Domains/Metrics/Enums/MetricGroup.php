<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

enum MetricGroup: string
{
    case Environment = 'environment';
    case Route = 'route';
    /** The host a request was made to. */
    case Domain = 'domain';
    case Host = 'host';
    case Queue = 'queue';
    case Release = 'release';
    /** A health check, by slug. */
    case Check = 'check';
}
