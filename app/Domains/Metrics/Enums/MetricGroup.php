<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Enums;

enum MetricGroup: string
{
    case Environment = 'environment';
    case Route = 'route';
    /** The host a request was made to. */
    case Domain = 'domain';
    case Host = 'host';
    case Queue = 'queue';
    case Release = 'release';
    /** A health check, by slug. */
    case Check = 'check';
    /** An activity, as group:name. */
    case Activity = 'activity';
    /** An AI provider. */
    case Provider = 'provider';
    /** An AI model. */
    case Model = 'model';
    /** An AI key, by name, else short id. */
    case Key = 'key';
    /** Whose AI key, as type:id. */
    case Owner = 'owner';
    /** Who caused an AI call, as type:id; counted per day. */
    case Actor = 'actor';
    /** Who pays for AI tokens: us or customer. */
    case Payer = 'payer';
}
