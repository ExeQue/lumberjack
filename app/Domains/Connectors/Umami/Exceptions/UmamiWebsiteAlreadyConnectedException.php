<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Exceptions;

use RuntimeException;

/**
 * Another of the project's connections already reads the website; the message is shown to the user.
 */
final class UmamiWebsiteAlreadyConnectedException extends RuntimeException implements UmamiExceptionInterface
{
}
