<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Performance\Queries\QueryStatisticsQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListQueries extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_queries';

    protected string $description = 'Database queries grouped by normalized SQL: calls, total time, share of database time, p95 and max in microseconds.';

    public function __construct(private readonly QueryStatisticsQuery $queries)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'environment' => $schema->string(),
            'sort' => $schema->string()->enum(['total', 'p95', 'count']),
            'search' => $schema->string()->description('Matches the SQL text.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json($this->queries->get(
            $project->id,
            Period::tryFrom((string) $request->get('period')) ?? Period::Day,
            $request->get('environment'),
            (string) $request->get('sort', 'total'),
            $request->get('search'),
            limit: 50,
        ));
    }
}
