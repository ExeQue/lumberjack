<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Presenters;

use App\Domains\Connectors\Enums\Connector;
use App\Domains\Connectors\Enums\ConnectorTag;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;

class ConnectorPresenter
{
    /**
     * The catalog as the Connectors page shows it: the connected ones first, then the rest,
     * each group by name, with the project's connections of each.
     *
     * @return list<array{key: string, label: string, description: string, tags: list<string>, url: string, connections: list<array{uuid: string, name: string, url: string}>}>
     */
    public function catalog(Project $project): array
    {
        $connections = $project->dataSourceConnections()->orderBy('name')->orderBy('id')
            ->get(['id', 'uuid', 'kind', 'name', 'environment'])
            ->groupBy(fn (DataSourceConnection $connection): string => $connection->kind->value);

        return array_values(collect(Connector::cases())
            ->map(fn (Connector $connector): array => [
                'key' => $connector->value,
                'label' => $connector->label(),
                'description' => $connector->description(),
                'tags' => array_map(fn (ConnectorTag $tag): string => $tag->value, $connector->tags()),
                'url' => $connector->url($project),
                'connections' => array_values($connections->get($connector->kind()->value, collect())
                    ->map(fn (DataSourceConnection $connection): array => [
                        'uuid' => $connection->uuid,
                        'name' => $connection->displayName(),
                        'url' => $connector->url($project, $connection),
                    ])->all()),
            ])
            ->sortBy([
                fn (array $a, array $b): int => ($b['connections'] !== []) <=> ($a['connections'] !== []),
                fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']),
            ])
            ->all());
    }

    /**
     * Every tag, to filter the catalog by.
     *
     * @return list<array{key: string, label: string}>
     */
    public function tags(): array
    {
        return array_values(collect(ConnectorTag::cases())
            ->map(fn (ConnectorTag $tag): array => ['key' => $tag->value, 'label' => $tag->label()])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->all());
    }
}
