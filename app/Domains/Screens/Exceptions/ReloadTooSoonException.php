<?php

declare(strict_types=1);

namespace App\Domains\Screens\Exceptions;

use RuntimeException;

/** A reload of the same screen or display was asked for moments ago. */
final class ReloadTooSoonException extends RuntimeException implements ScreenExceptionInterface
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(__('screens.reload.too_soon', ['seconds' => $retryAfter]));
    }
}
