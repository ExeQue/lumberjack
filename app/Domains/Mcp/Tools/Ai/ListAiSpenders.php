<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Ai, McpLevel::Read)]
class ListAiSpenders extends Tool
{
    use UsesAiUsage;

    protected string $name = 'list_ai_spenders';

    protected string $description = 'The keys, key owners (tenants who brought their own key) or actors (who caused the calls, such as a user) that cost the most in the period, costliest first, 10 a page, each with its tokens, cost and previous_cost (the period before). Actors count whole days. Call again with the next page while has_more is true.';

    public function __construct(private readonly AiUsagePresenter $usage)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'kind' => $schema->string()->enum(['keys', 'owners', 'actors'])->description('Defaults to keys.'),
            'period' => $this->periodArgument($schema),
            'environment' => $schema->string(),
            'search' => $schema->string()->description('A key by name, label, short id, provider or owner; an owner or actor as type:id. * stands for anything.'),
            'page' => $schema->integer()->min(1)->description('Defaults to 1.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $validated = $request->validate([
            'kind' => ['nullable', 'in:keys,owners,actors'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $kind = match ($validated['kind'] ?? null) {
            'owners' => 'owners',
            'actors' => 'actors',
            default => 'keys',
        };

        $page = $this->usage->top($project, $this->period($request), $this->environment($request), $kind, trim((string) ($validated['search'] ?? '')), (int) ($validated['page'] ?? 1), '');

        return Response::json([
            'kind' => $kind,
            'spenders' => $page['data'],
            'page' => $page['current_page'],
            'last_page' => $page['last_page'],
            'total' => $page['total'],
            'has_more' => $page['current_page'] < $page['last_page'],
        ]);
    }
}
