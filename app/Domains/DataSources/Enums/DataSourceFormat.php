<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Enums;

/**
 * What a data source answers, and so what a widget reads from it with: JSON read with
 * JSONPath, or the Prometheus text format read with a PromQL-style selector.
 */
enum DataSourceFormat: string
{
    case Json = 'json';
    case Prometheus = 'prometheus';
}
