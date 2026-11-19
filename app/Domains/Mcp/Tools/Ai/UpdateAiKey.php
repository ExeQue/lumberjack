<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Actions\SetAiKeyNameAction;
use App\Domains\Ai\Actions\SetAiKeyPayerAction;
use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Ai\Http\Requests\SetAiKeyNameRequest;
use App\Domains\Ai\Http\Requests\SetAiPayerRequest;
use App\Domains\Ai\Presenters\AiUsagePresenter;
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
class UpdateAiKey extends Tool
{
    use UsesAiUsage;

    protected string $name = 'update_ai_key';

    protected string $description = 'Names an AI key in Lumberjack, or sets who pays for it; pass only what changes. Returns the key.';

    public function __construct(
        private readonly SetAiKeyNameAction $setName,
        private readonly SetAiKeyPayerAction $setPayer,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'key' => $this->keyArgument($schema),
            'name' => $schema->string()->description('Shown before the label the app reports. Empty or null removes it.'),
            'payer' => $schema->string()->enum(array_column(AiPayer::cases(), 'value'))->description('Null inherits: customer for a key with an owner, else the project\'s default.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $key = $this->aiKey($request, $project);

        if ($key instanceof Response) {
            return $key;
        }

        $denied = $this->authorize('update', $project);

        if ($denied !== null) {
            return $denied;
        }

        $given = $request->all();
        $validated = $request->validate([...SetAiKeyNameRequest::rulesFor(), ...SetAiPayerRequest::rulesFor(forKey: true)]);

        if (array_key_exists('name', $given)) {
            $this->setName->execute($key, filled($validated['name'] ?? null) ? (string) $validated['name'] : null);
        }

        if (array_key_exists('payer', $given)) {
            $this->setPayer->execute($key, AiPayer::tryFrom((string) ($validated['payer'] ?? '')));
        }

        return Response::json(['key' => [
            ...AiUsagePresenter::key($key->refresh()),
            'effective_payer' => $key->effectivePayer($project->ai_default_payer)->value,
        ]]);
    }
}
