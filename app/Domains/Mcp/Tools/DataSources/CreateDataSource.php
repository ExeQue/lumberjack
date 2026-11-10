<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\DataSources\Actions\SaveDataSourceAction;
use App\Domains\DataSources\Http\Requests\SaveDataSourceRequest;
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

#[RequiresPermission(McpArea::DataSources, McpLevel::Write)]
class CreateDataSource extends Tool
{
    use UsesDataSources;

    protected string $name = 'create_data_source';

    protected string $description = 'Adds an endpoint that Lumberjack fetches every interval, for widgets to read; it is fetched within a minute. Put credentials in headers, never in the URL; header values are stored encrypted and never shown again.';

    public function __construct(
        private readonly SaveDataSourceAction $save,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'name' => $schema->string()->required(),
            'url' => $schema->string()->description('A public http or https address that answers a GET; webcal:// works for a calendar.')->required(),
            'format' => $schema->string()->enum(['json', 'prometheus', 'ical'])->required(),
            'interval_minutes' => $schema->integer()->description('How often to fetch it, 1 to 1440.')->required(),
            'timeout_s' => $schema->integer()->description('How long to wait for an answer, in seconds, at most 15.')->required(),
            'headers' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'value' => $schema->string()->required(),
            ]))->description('Request headers sent with each fetch, such as Authorization.'),
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
            $input['url'] = SaveDataSourceRequest::normalizeUrl($input['url']);
        }

        $validated = Validator::make($input, SaveDataSourceRequest::rulesFor())
            ->after(fn (ValidationValidator $validator) => SaveDataSourceRequest::checkHeaderValues($validator, null, $input['url'] ?? null, $input['headers'] ?? []))
            ->validate();

        return Response::json($this->sourceSummary($this->save->execute($project, null, $validated)));
    }
}
