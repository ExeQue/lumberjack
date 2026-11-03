<?php

declare(strict_types=1);

namespace App\Domains\Retention\Enums;

/**
 * Where a retention comes from, broadest first. Each level is capped by the ones above it.
 */
enum RetentionLevel: string
{
    case Default = 'default';
    case Application = 'application';
    case Team = 'team';
    case Project = 'project';
    /** A job class or log channel of a project. */
    case Override = 'override';
}
