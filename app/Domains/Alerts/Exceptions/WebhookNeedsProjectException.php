<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Exceptions;

use RuntimeException;

final class WebhookNeedsProjectException extends RuntimeException implements AlertExceptionInterface
{
    public static function create(): self
    {
        return new self(__('alerts.webhook_needs_project'));
    }
}
