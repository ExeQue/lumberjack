<?php

declare(strict_types=1);

namespace App\Domains\Retention\Enums;

enum RetentionCategory: string
{
    case Errors = 'errors';
    case Jobs = 'jobs';
    case Logs = 'logs';
    case Performance = 'performance';

    public function label(): string
    {
        return __('retention.categories.'.$this->value);
    }

    /**
     * Days kept when neither team nor project says otherwise.
     */
    public function defaultDays(): int
    {
        return match ($this) {
            self::Errors => 90,
            self::Jobs => 30,
            self::Logs, self::Performance => 14,
        };
    }
}
