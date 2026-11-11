<?php

declare(strict_types=1);

namespace App\Domains\OpenTelemetry\Exceptions;

use RuntimeException;

/**
 * An export holding more events than an agent's batch may; nothing of it is kept.
 */
class TooManyEventsException extends RuntimeException implements OpenTelemetryExceptionInterface
{
}
