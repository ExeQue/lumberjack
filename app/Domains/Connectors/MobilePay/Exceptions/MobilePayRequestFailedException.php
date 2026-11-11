<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Exceptions;

use RuntimeException;

/**
 * Vipps MobilePay could not be asked, or did not answer what was asked; the message is shown to the user.
 */
final class MobilePayRequestFailedException extends RuntimeException implements MobilePayExceptionInterface
{
}
