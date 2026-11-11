<?php

declare(strict_types=1);

namespace App\Domains\Connectors\GitHub\Exceptions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;

/**
 * Anything thrown by the GitHub connector; a ConnectorsExceptionInterface too.
 */
interface GitHubExceptionInterface extends ConnectorsExceptionInterface
{
}
