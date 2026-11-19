<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Presenters\AiBudgetPresenter;
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
class ListAiBudgets extends Tool
{
    use UsesAiUsage;

    protected string $name = 'list_ai_budgets';

    protected string $description = 'The project\'s AI budgets: what each counts (scope project, key, owner or actor, with its keys or references), per day or month, in cost or tokens (basis), whose usage (payer), the amount, and its usage this period: spent, percent, projected (at the period\'s end at the pace so far). status is over, projected_over or ok.';

    public function __construct(private readonly AiBudgetPresenter $budgets)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $budgets = $project->aiBudgets()->with('project.team')->orderBy('name')->orderBy('id')->get();

        return Response::json(['budgets' => array_map($this->withStatus(...), $this->budgets->presentAll($budgets))]);
    }
}
