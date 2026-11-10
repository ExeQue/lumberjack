<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\DataSources;

use App\Domains\DataSources\Actions\SaveDataSourceAction;
use App\Domains\DataSources\Enums\DataSourceFormat;
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
class UpdateDataSource extends Tool
{
    use UsesDataSources;

    protected string $name = 'update_data_source';

    protected string $description = 'Changes a fetched data source; pass only what changes. headers replaces the list: a header passed without a value keeps the one it has, unless the URL now points at another server.';

    public function __construct(
        private readonly SaveDataSourceAction $save,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->dataSourceArguments($schema),
            'name' => $schema->string(),
            'url' => $schema->string()->description('A public http or https address that answers a GET.'),
            'format' => $schema->string()->enum(['json', 'prometheus', 'ical']),
            'interval_minutes' => $schema->integer()->description('1 to 1440.'),
            'timeout_s' => $schema->integer()->description('At most 15.'),
            'headers' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'value' => $schema->string()->description('Omit to keep the value it has.'),
            ]))->description('Every header the source should send; omit to keep them all.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $source = $this->dataSource($request, 'update');

        if ($source instanceof Response) {
            return $source;
        }

        if ($source->format === DataSourceFormat::Connected) {
            return Response::error('A connected source is set up by its app; change the connection with update_data_source_connection.');
        }

        // What is not passed stays: the URL as shown (SaveDataSourceAction keeps it), the headers with their values.
        $input = [
            'name' => $source->name,
            'url' => $source->maskedUrl(),
            'format' => $source->format->value,
            'interval_minutes' => $source->interval_minutes,
            'timeout_s' => $source->timeout_s,
            'headers' => array_map(fn (string $name): array => ['name' => $name], $source->headerNames()),
            ...array_intersect_key($request->all(), SaveDataSourceRequest::rulesFor()),
        ];

        if (is_string($input['url'])) {
            $input['url'] = SaveDataSourceRequest::normalizeUrl($input['url']);
        }

        $validated = Validator::make($input, SaveDataSourceRequest::rulesFor())
            ->after(fn (ValidationValidator $validator) => SaveDataSourceRequest::checkHeaderValues($validator, $source, $input['url'], $input['headers']))
            ->validate();

        return Response::json($this->sourceSummary($this->save->execute($source->project, $source, $validated)));
    }
}
