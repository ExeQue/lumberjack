<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Enums;

use App\Domains\DataSources\Enums\ConnectionKind;
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

    public function url(Project $project): string
    {
        return match ($this) {
            self::Umami => route('connectors.umami.index', $project),
            self::Mosparo => route('connectors.mosparo.index', $project),
            self::GitHub => route('connectors.github.index', $project),
            self::MobilePay => route('connectors.mobilepay.index', $project),
        };
    }

    /**
     * The catalog as a page shows it, with how many of each the project has connected.
     *
     * @return list<array{key: string, label: string, description: string, connections: int, url: string}>
     */
    public static function catalog(Project $project): array
    {
        $connected = $project->dataSourceConnections()->toBase()->selectRaw('kind, count(*) as connections')->groupBy('kind')->pluck('connections', 'kind')->all();

        return array_map(fn (self $connector): array => [
            'key' => $connector->value,
            'label' => $connector->label(),
            'description' => $connector->description(),
            'connections' => (int) ($connected[$connector->kind()->value] ?? 0),
            'url' => $connector->url($project),
        ], self::cases());
    }
}
