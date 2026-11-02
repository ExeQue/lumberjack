<?php

declare(strict_types=1);

namespace App\Domains\Performance\Enums;

/**
 * What a span on a trace is, as the SDK reports it.
 */
enum SpanKind: string
{
    case Cache = 'cache';
    case Http = 'http';
    case Mail = 'mail';
    case Notification = 'notification';
    case Redis = 'redis';
}
