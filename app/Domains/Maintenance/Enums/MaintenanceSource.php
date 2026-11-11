<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Enums;

enum MaintenanceSource: string
{
    /** Created ahead in the UI or over MCP, with a planned end. */
    case Planned = 'planned';

    /** Started by the app's own maintenance event, e.g. on `artisan down`. */
    case Reported = 'reported';

    public function label(): string
    {
        return __('maintenance.sources.'.$this->value);
    }
}
