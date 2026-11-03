<?php

declare(strict_types=1);

namespace App\Domains\Issues\Enums;

/**
 * How someone came to watch an issue: by choice, or by acting on it.
 */
enum WatchReason: string
{
    case Manual = 'manual';
    case Assigned = 'assigned';
    case Noted = 'noted';
    case Mentioned = 'mentioned';
    case Resolved = 'resolved';

    /**
     * "you wrote a note on it", or nothing for someone who chose to watch.
     */
    public function phrase(): ?string
    {
        return $this === self::Manual ? null : (string) __('mail.watching.why.'.$this->value);
    }
}
