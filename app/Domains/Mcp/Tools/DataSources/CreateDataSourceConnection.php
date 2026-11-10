<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\DataSources\Actions\CreateDataSourceConnectionAction;
use App\Domains\DataSources\Http\Requests\SaveDataSourceConnectionRequest;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesDataSources;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::DataSources, McpLevel::Manage)]
class CreateDataSourceConnection extends Tool
{
    use UsesDataSources;

    protected string $name = 'create_data_source_connection';

    protected string $description = 'Connects one environment of an app using the Laravel package, by the https address of its data sources endpoint. The pull token is never sent over MCP: a team admin makes one with Rotate token on the Data sources page and sets it as LUMBERJACK_PULL_TOKEN in the app.';

    public function __construct(
        private readonly CreateDataSourceConnectionAction $create,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'environment' => $schema->string()->description('Such as production.')->required(),
            'url' => $schema->string()->description('Such as https://your-app.example/lumberjack/datasources, without a query.')->required(),
            'timezone' => $schema->string()->description('Where the app\'s days begin, such as Europe/Copenhagen. Default UTC.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        if ($denied = $this->authorize('update', $project)) {
            return $denied;
        }

        $input = $request->all();

        if (is_string($input['url'] ?? null)) {
            $input['url'] = SaveDataSourceConnectionRequest::normalizeUrl($input['url']);
        }

        $validated = Validator::make($input, SaveDataSourceConnectionRequest::rulesFor($project))
            ->after(fn (ValidationValidator $validator) => SaveDataSourceConnectionRequest::checkLimit($validator, $project))
            ->validate();

        $this->create->execute($project, $validated['environment'], $validated['url'], $validated['timezone'] ?? 'UTC');
        $connection = DataSourceConnection::query()->where('project_id', $project->id)->where('environment', $validated['environment'])->firstOrFail();

        return Response::json([
            ...$this->connectionSummary($connection),
            'next_step' => 'Ask a team admin to click Rotate token for this connection on the project\'s Data sources page and set it as LUMBERJACK_PULL_TOKEN in the app. The token is never sent over MCP.',
        ]);
    }
}
