<?php

declare(strict_types=1);

namespace App\Domains\Performance\Enums;

/**
 * The kind of device a browser page view was measured on, as the browser script tells it.
 */
enum DeviceClass: string
{
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Desktop = 'desktop';
}
