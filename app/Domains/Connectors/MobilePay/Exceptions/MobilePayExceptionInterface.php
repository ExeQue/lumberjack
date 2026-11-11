<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Exceptions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;

/**
 * Anything thrown by the MobilePay connector; a ConnectorsExceptionInterface too.
 */
interface MobilePayExceptionInterface extends ConnectorsExceptionInterface
{
}
