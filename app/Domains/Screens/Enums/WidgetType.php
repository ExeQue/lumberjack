<?php

declare(strict_types=1);

namespace App\Domains\Screens\Enums;

/**
 * A status screen widget's visualization. What it shows is a source of that type
 * (WidgetSources); a new metric is a new source, never a new type.
 */
enum WidgetType: string
{
    case Stat = 'stat';
    case Gauge = 'gauge';
    case Chart = 'chart';
    case List = 'list';
    case Health = 'health';
    /** Every project of the scope as a traffic light. */
    case Overview = 'overview';
    /** One service level objective and its error budget. */
    case Slo = 'slo';
}
