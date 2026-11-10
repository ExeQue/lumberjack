<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Data sources and app connections, looked up by uuid within the project the request names.
 * What they return never holds header values, pull tokens or a full URL.
 */
trait UsesDataSources
{
    use UsesMcpContext;

    /**
     * @return array<string, mixed>
     */
    protected function dataSourceArguments(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'data_source' => $schema->string()->description('The data source\'s uuid; see list_data_sources.')->required(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionArguments(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'connection' => $schema->string()->description('The connection\'s uuid; see list_data_source_connections.')->required(),
        ];
    }

    /**
     * The source the request names in its project, if the token's maker may do $ability on it.
     */
    protected function dataSource(Request $request, string $ability = 'view'): DataSource|Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $source = $project->dataSources()->where('uuid', (string) $request->get('data_source'))->first();

        if ($source === null) {
            return Response::error('Unknown data source in project '.$project->slug.'; see list_data_sources.');
        }

        return $this->authorize($ability, $source) ?? $source->setRelation('project', $project);
    }

    /**
     * The connection the request names in its project, if the token's maker may change the project,
     * as the web page asks for every change to a connection.
     */
    protected function connection(Request $request): DataSourceConnection|Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $connection = $project->dataSourceConnections()->where('uuid', (string) $request->get('connection'))->first();

        if ($connection === null) {
            return Response::error('Unknown connection in project '.$project->slug.'; see list_data_source_connections.');
        }

        return $this->authorize('update', $project) ?? $connection->setRelation('project', $project);
    }

    /**
     * A source as an agent sees it: the URL with its query values masked, the names of its headers only.
     *
     * @return array<string, mixed>
     */
    protected function sourceSummary(DataSource $source): array
    {
        return [
            'uuid' => $source->uuid,
            'name' => $source->name,
            'url' => $source->maskedUrl(),
            'format' => $source->format->value,
            'header_names' => $source->headerNames(),
            'interval_minutes' => $source->interval_minutes,
            'timeout_s' => $source->timeout_s,
            'last_fetched_at' => $source->last_fetched_at?->toIso8601String(),
            'last_ok_at' => $source->last_ok_at?->toIso8601String(),
            'last_error' => $source->last_error,
        ];
    }

    /**
     * A connection as an agent sees it: its host, never its URL or token.
     *
     * @return array<string, mixed>
     */
    protected function connectionSummary(DataSourceConnection $connection): array
    {
        return [
            'uuid' => $connection->uuid,
            'kind' => $connection->kind->value,
            'environment' => $connection->environment,
            'website' => $connection->website_name,
            'host' => parse_url($connection->url, PHP_URL_HOST) ?: null,
            'timezone' => $connection->timezone,
            'status' => $connection->status(),
            'last_ok_at' => $connection->last_ok_at?->toIso8601String(),
            'last_error' => $connection->last_error,
        ];
    }
}
