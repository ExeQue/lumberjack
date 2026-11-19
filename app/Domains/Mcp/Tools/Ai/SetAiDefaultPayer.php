<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Actions\SetProjectAiPayerAction;
use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Http\Requests\SetAiPayerRequest;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[RequiresPermission(McpArea::Ai, McpLevel::Write)]
class SetAiDefaultPayer extends Tool
{
    use UsesAiUsage;

    protected string $name = 'set_ai_default_payer';

    protected string $description = 'Sets who pays for the project\'s AI keys that do not say: us or the customer. A key with an owner is the customer\'s unless set otherwise. Returns the default.';

    public function __construct(private readonly SetProjectAiPayerAction $setPayer)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'payer' => $schema->string()->enum(array_column(AiPayer::cases(), 'value'))->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $denied = $this->authorize('update', $project);

        if ($denied !== null) {
            return $denied;
        }

        $validated = $request->validate(SetAiPayerRequest::rulesFor(forKey: false));
        $this->setPayer->execute($project, AiPayer::from((string) $validated['payer']));

        return Response::json(['default_payer' => $project->refresh()->ai_default_payer->value]);
    }
}
