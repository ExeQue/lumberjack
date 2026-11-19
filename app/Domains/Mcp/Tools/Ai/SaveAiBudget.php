<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Actions\SaveAiBudgetAction;
use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Http\Requests\SaveAiBudgetRequest;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Presenters\AiBudgetPresenter;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Ai, McpLevel::Write)]
class SaveAiBudget extends Tool
{
    use UsesAiUsage;

    protected string $name = 'save_ai_budget';

    protected string $description = 'Adds an AI budget, or changes one when "budget" is given; when changing, pass only what changes. A budget counts the project, some keys, key owners or actors, per day or month, in cost (the team\'s currency) or tokens. Returns the budget as list_ai_budgets shows it.';

    public function __construct(
        private readonly SaveAiBudgetAction $save,
        private readonly AiBudgetPresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        $references = fn (): mixed => $schema->array()->items($schema->object([
            'type' => $schema->string()->required(),
            'id' => $schema->string()->required(),
        ]));

        return [
            'project' => $this->projectArgument($schema),
            'budget' => $schema->string()->description('The budget\'s uuid to change; see list_ai_budgets. Leave out to add one.'),
            'name' => $schema->string(),
            'scope' => $schema->string()->enum(array_column(AiBudgetScope::cases(), 'value'))->description('Required when adding.'),
            'keys' => $schema->array()->items($schema->string())->description('For scope key: key uuids, see list_ai_spenders.'),
            'owners' => $references()->description('For scope owner: key owners as type and id.'),
            'actors' => $references()->description('For scope actor: actors as type and id.'),
            'period' => $schema->string()->enum(array_column(AiBudgetPeriod::cases(), 'value'))->description('Required when adding.'),
            'basis' => $schema->string()->enum(array_column(AiBudgetBasis::cases(), 'value'))->description('Required when adding.'),
            'payer' => $schema->string()->enum(array_column(AiBudgetPayer::cases(), 'value'))->description('Whose usage counts. Empty: everyone for an owner or actor, us for the project or a key.'),
            'amount' => $schema->number()->description('In the team\'s currency, or whole tokens. Required when adding.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $budget = $request->get('budget') !== null ? $this->aiBudget($request, $project) : null;

        if ($budget instanceof Response) {
            return $budget;
        }

        $denied = $this->authorize('update', $budget ?? $project);

        if ($denied !== null) {
            return $denied;
        }

        $input = [...($budget !== null ? $this->current($budget) : []), ...$request->all()];
        $choices = SaveAiBudgetRequest::choicesFor($project, $budget, $input['keys'] ?? []);

        $validated = Validator::make($input, SaveAiBudgetRequest::rulesFor($choices, $input['basis'] ?? null))
            ->after(SaveAiBudgetRequest::duplicatesFor($input))
            ->validate();

        $saved = $this->save->execute($project, $budget, SaveAiBudgetRequest::valuesFrom($validated, $choices));

        return Response::json(['budget' => $this->withStatus($this->presenter->present($saved->load('project.team')))]);
    }

    /**
     * The budget as the edit form would send it, so what is not passed stays.
     *
     * @return array<string, mixed>
     */
    private function current(AiBudget $budget): array
    {
        $presented = $this->presenter->present($budget);

        return [
            'name' => $budget->name,
            'scope' => $budget->scope->value,
            'keys' => array_column($presented['keys'], 'value'),
            'owners' => $budget->scope === AiBudgetScope::Owner ? $budget->references() : [],
            'actors' => $budget->scope === AiBudgetScope::Actor ? $budget->references() : [],
            'period' => $budget->period->value,
            'basis' => $budget->basis->value,
            'payer' => $budget->payer->value,
            'amount' => $budget->amount,
        ];
    }
}
