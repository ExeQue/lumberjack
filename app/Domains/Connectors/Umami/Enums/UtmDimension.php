<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Enums;

/**
 * The UTM parameters Umami breaks visits down by, by Umami's own names.
 */
enum UtmDimension: string
{
    case Source = 'utmSource';
    case Medium = 'utmMedium';
    case Campaign = 'utmCampaign';
    case Content = 'utmContent';
    case Term = 'utmTerm';
}
