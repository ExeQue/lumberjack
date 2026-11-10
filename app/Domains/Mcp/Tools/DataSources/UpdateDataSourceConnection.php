<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\DataSources\Actions\ChangeDataSourceConnectionUrlAction;
use App\Domains\DataSources\Http\Requests\SaveDataSourceConnectionRequest;
use App\Domains\DataSources\Http\Requests\UpdateDataSourceConnectionRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesDataSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::DataSources, McpLevel::Manage)]
class UpdateDataSourceConnection extends Tool
{
    use UsesDataSources;

    protected string $name = 'update_data_source_connection';

    protected string $description = 'Changes where an app that moved is pulled from, or its time zone; pass only what changes. Its token, sources and widgets stay, and pulls go out anew at once.';

    public function __construct(
        private readonly ChangeDataSourceConnectionUrlAction $change,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->connectionArguments($schema),
            'url' => $schema->string()->description('The https address of its data sources endpoint, without a query, on the same host; a new host is set on the Data sources page.'),
            'timezone' => $schema->string()->description('Where the app\'s days begin, such as Europe/Copenhagen.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $connection = $this->connection($request);

        if ($connection instanceof Response) {
            return $connection;
        }

        $url = $request->get('url');
        $input = [
            'url' => is_string($url) ? SaveDataSourceConnectionRequest::normalizeUrl($url) : $connection->url,
            ...array_intersect_key($request->all(), ['timezone' => true]),
        ];
        $validated = Validator::make($input, UpdateDataSourceConnectionRequest::rulesFor($connection->project))->validate();

        // The pull token goes wherever the url points: only a person may move it to another host.
        if (strcasecmp((string) parse_url((string) $validated['url'], PHP_URL_HOST), (string) parse_url($connection->url, PHP_URL_HOST)) !== 0) {
            return Response::error('Moving a connection to another host is done on the Data sources page, where the pull token can be rotated with it.');
        }

        $this->change->execute($connection, $validated['url'], $validated['timezone'] ?? null);

        return Response::json($this->connectionSummary($connection->refresh()));
    }
}
