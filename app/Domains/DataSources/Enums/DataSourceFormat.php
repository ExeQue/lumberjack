<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Enums;

/**
 * What a data source answers, and so what a widget reads from it with: JSON read with
 * JSONPath, the Prometheus text format read with a PromQL-style selector, or an
 * iCalendar feed, read by the calendar list.
 */
enum DataSourceFormat: string
{
    case Json = 'json';
    case Prometheus = 'prometheus';
    case Ical = 'ical';
}
