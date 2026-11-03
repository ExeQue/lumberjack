<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

enum MetricGroup: string
{
    case Environment = 'environment';
    case Route = 'route';
    case Host = 'host';
    case Queue = 'queue';
    case Release = 'release';
}
