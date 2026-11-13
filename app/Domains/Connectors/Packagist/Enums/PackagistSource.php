<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Packagist\Enums;

use App\Domains\DataSources\Enums\DataSourceShape;

/**
 * The data sources a Packagist package offers screens.
 */
enum PackagistSource: string
{
    case Downloads = 'downloads';
    case DownloadsSeries = 'downloads.series';
    case TotalDownloads = 'total_downloads';
    case Favers = 'favers';
    case Dependents = 'dependents';
    case Advisories = 'advisories';

    public function label(): string
    {
        return __('connectors.packagist.sources.'.str_replace('.', '_', $this->value));
    }

    public function shape(): DataSourceShape
    {
        return $this === self::DownloadsSeries ? DataSourceShape::Series : DataSourceShape::Number;
    }
}
