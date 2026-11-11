<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Enums;

/** What a connector is about, to find one by on the Connectors page. */
enum ConnectorTag: string
{
    case Analytics = 'analytics';
    case Forms = 'forms';
    case Development = 'development';
    case Payments = 'payments';
    case Security = 'security';
    case Commerce = 'commerce';

    public function label(): string
    {
        return __('connectors.tags.'.$this->value);
    }
}
