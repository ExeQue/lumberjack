<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Performance\Queries\BrowserQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class ListBrowserErrors extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_browser_errors';

    protected string $description = 'Frontend (browser) errors in the period: the total occurrences and the issues with the most of them. Pass an issue id to get_issue for details.';

    public function __construct(private readonly BrowserQuery $browser)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d']),
            'environment' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(50)->description('Defaults to 20.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $environment = (string) $request->get('environment', '');

        return Response::json($this->browser->errors(
            $project->id,
            Period::tryFrom((string) $request->get('period')) ?? Period::Day,
            $environment !== '' ? $environment : null,
            min(50, max(1, (int) $request->get('limit', 20))),
        ));
    }
}
