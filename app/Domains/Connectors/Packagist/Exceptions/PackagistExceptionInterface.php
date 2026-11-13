<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Exceptions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;

/**
 * Anything thrown by the Packagist connector; a ConnectorsExceptionInterface too.
 */
interface PackagistExceptionInterface extends ConnectorsExceptionInterface
{
}
