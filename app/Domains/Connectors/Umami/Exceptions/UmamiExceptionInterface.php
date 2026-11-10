<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Exceptions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;

/**
 * Anything thrown by the Umami connector; a ConnectorsExceptionInterface too.
 */
interface UmamiExceptionInterface extends ConnectorsExceptionInterface
{
}
