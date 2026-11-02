<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Exceptions;

use RuntimeException;

final class AlertDeliveryFailedException extends RuntimeException implements AlertExceptionInterface
{
    public static function notPublic(string $channel): self
    {
        return new self("Delivery to {$channel} refused: its URL no longer points at a public address.");
    }

    public static function unreachable(string $channel): self
    {
        return new self("Delivery to {$channel} failed: the address could not be reached in time.");
    }

    public static function status(string $channel, int $status): self
    {
        return new self("Delivery to {$channel} failed with HTTP {$status}.");
    }
}
