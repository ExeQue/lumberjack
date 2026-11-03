<?php

declare(strict_types=1);

namespace App\Domains\Health\Exceptions;

use RuntimeException;

/**
 * A connection, handshake or lookup a check makes did not succeed; the message is shown as the result.
 */
final class ProbeFailedException extends RuntimeException implements HealthExceptionInterface
{
}
