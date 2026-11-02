<?php

declare(strict_types=1);

namespace App\Domains\Issues\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    /**
     * Open or being worked on: not done with, so it counts and alerts as open.
     */
    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    public function label(): string
    {
        return __('issues.statuses.'.$this->value);
    }
}
