<?php

declare(strict_types=1);

namespace App\Domains\Issues\Enums;

enum IssueSource: string
{
    case Server = 'server';
    case Browser = 'browser';
    case Log = 'log';

    public function label(): string
    {
        return __('issues.sources.'.$this->value);
    }
}
