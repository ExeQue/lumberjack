<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Exceptions\Umami;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;
use RuntimeException;

/**
 * Umami could not be asked, or did not answer what was asked; the message is shown to the user.
 */
final class UmamiRequestFailedException extends RuntimeException implements ConnectorsExceptionInterface
{
}
