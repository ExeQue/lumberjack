<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Commands\Queries\CommandStatisticsQuery;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListCommands extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_commands';

    protected string $description = 'Artisan commands the app ran in the period: runs, failed runs (non-zero exit), average and longest duration in microseconds, and the last run with its status.';

    public function __construct(private readonly CommandStatisticsQuery $commands)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'environment' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        return Response::json(['commands' => $this->commands->get(
            $project->id,
            Period::tryFrom((string) $request->get('period')) ?? Period::Day,
            $request->get('environment'),
            50,
        )]);
    }
}
