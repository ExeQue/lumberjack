<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Exceptions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;

/**
 * Anything thrown by the Mosparo connector; a ConnectorsExceptionInterface too.
 */
interface MosparoExceptionInterface extends ConnectorsExceptionInterface
{
}
