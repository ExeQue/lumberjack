<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Exceptions;

use RuntimeException;

/**
 * Packagist could not be asked, or did not answer what was asked; the message is shown to the user.
 */
final class PackagistRequestFailedException extends RuntimeException implements PackagistExceptionInterface
{
}
