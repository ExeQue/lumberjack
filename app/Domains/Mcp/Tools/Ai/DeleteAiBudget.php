<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Actions\DeleteAiBudgetAction;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[RequiresPermission(McpArea::Ai, McpLevel::Manage)]
class DeleteAiBudget extends Tool
{
    use UsesAiUsage;

    protected string $name = 'delete_ai_budget';

    protected string $description = 'Deletes an AI budget; its alerts stop. The usage it counted stays.';

    public function __construct(private readonly DeleteAiBudgetAction $delete)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'budget' => $schema->string()->description('The budget\'s uuid; see list_ai_budgets.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $budget = $this->aiBudget($request, $project);

        if ($budget instanceof Response) {
            return $budget;
        }

        $denied = $this->authorize('delete', $budget);

        if ($denied !== null) {
            return $denied;
        }

        $this->delete->execute($budget);

        return Response::json(['deleted' => $budget->uuid]);
    }
}
