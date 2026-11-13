<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Enums;

use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;

/**
 * The catalog of outside services a project can connect to, each with a page of its own
 * where it is connected and read.
 */
enum Connector: string
{
    case Umami = 'umami';
    case Mosparo = 'mosparo';
    case GitHub = 'github';
    case MobilePay = 'mobilepay';
    case Packagist = 'packagist';

    public function label(): string
    {
        return __('connectors.catalog.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('connectors.catalog.'.$this->value.'.description');
    }

    /** The connection kind it is saved as. */
    public function kind(): ConnectionKind
    {
        return match ($this) {
            self::Umami => ConnectionKind::Umami,
            self::Mosparo => ConnectionKind::Mosparo,
            self::GitHub => ConnectionKind::GitHub,
            self::MobilePay => ConnectionKind::MobilePay,
            self::Packagist => ConnectionKind::Packagist,
        };
    }

    /** The connector a connection was made with; none for an app's. */
    public static function forKind(ConnectionKind $kind): ?self
    {
        foreach (self::cases() as $connector) {
            if ($connector->kind() === $kind) {
                return $connector;
            }
        }

        return null;
    }

    /**
     * What it is about, to find it by on the Connectors page.
     *
     * @return list<ConnectorTag>
     */
    public function tags(): array
    {
        return match ($this) {
            self::Umami => [ConnectorTag::Analytics],
            self::Mosparo => [ConnectorTag::Forms, ConnectorTag::Security],
            self::GitHub => [ConnectorTag::Development, ConnectorTag::Security],
            self::MobilePay => [ConnectorTag::Payments, ConnectorTag::Commerce],
            self::Packagist => [ConnectorTag::Development, ConnectorTag::Security],
        };
    }

    /** Its page, opened at one of its connections when given. */
    public function url(Project $project, ?DataSourceConnection $connection = null): string
    {
        $route = match ($this) {
            self::Umami => 'connectors.umami.index',
            self::Mosparo => 'connectors.mosparo.index',
            self::GitHub => 'connectors.github.index',
            self::MobilePay => 'connectors.mobilepay.index',
            self::Packagist => 'connectors.packagist.index',
        };

        return route($route, $connection === null ? [$project] : [$project, $connection]);
    }
}
