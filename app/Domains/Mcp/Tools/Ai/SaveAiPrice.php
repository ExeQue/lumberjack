<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Actions\SaveAiPriceAction;
use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Http\Requests\SaveAiPriceRequest;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesAiUsage;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[RequiresPermission(McpArea::Ai, McpLevel::Write)]
class SaveAiPrice extends Tool
{
    use UsesAiUsage;

    protected string $name = 'save_ai_price';

    protected string $description = 'Adds an AI price, or changes one when "price" is given; when changing, pass only what changes. Prices are per million tokens in the team\'s currency, for a model name or a glob with *, from valid_from on; the past is priced again with it, as cost is never stored. The most specific price applies: a key\'s, then an owner\'s, then the project\'s, then the team\'s. Returns the price.';

    public function __construct(private readonly SaveAiPriceAction $save)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        $price = fn (string $description): mixed => $schema->number()->min(0)->description($description);

        return [
            'project' => $this->projectArgument($schema),
            'price' => $schema->string()->description('The price\'s uuid to change; see get_ai_key. Leave out to add one.'),
            'scope' => $schema->string()->enum(array_column(AiPriceScope::cases(), 'value'))->description('What it applies to. Required when adding.'),
            'team_wide' => $schema->boolean()->description('For scope project: every project of the team.'),
            'owner_type' => $schema->string()->description('For scope owner.'),
            'owner_id' => $schema->string()->description('For scope owner.'),
            'key' => $schema->string()->description('For scope key: the key\'s uuid; see list_ai_spenders.'),
            'provider' => $schema->string()->description('Empty for any, e.g. anthropic.'),
            'model_pattern' => $schema->string()->description('A model name, or a glob with *. Required when adding.'),
            'valid_from' => $schema->string()->description('ISO 8601. Required when adding.'),
            'input' => $price('Input tokens. Required when adding.'),
            'output' => $price('Output tokens. Required when adding.'),
            'cache_read' => $price('Empty: the input price.'),
            'cache_write' => $price('Empty: the input price.'),
            'reasoning' => $price('Empty: billed as output.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $price = $request->get('price') !== null ? $this->aiPrice($request, $project) : null;

        if ($price instanceof Response) {
            return $price;
        }

        $denied = $price !== null ? $this->authorizePrice('update', $price, $project) : $this->authorize('update', $project);

        if ($denied !== null) {
            return $denied;
        }

        $input = [...($price !== null ? $this->current($price, $project) : []), ...$request->all()];
        $validated = Validator::make($input, SaveAiPriceRequest::rulesFor($project))->validate();

        $saved = $this->save->execute($project, $price, AiPriceScope::from((string) $validated['scope']), SaveAiPriceRequest::valuesFrom($project, $validated));
        $key = $saved->scope === AiPriceScope::Key ? AiKey::query()->whereProject($project)->whereFingerprint((string) $saved->scope_id)->first() : null;

        return Response::json(['price' => AiUsagePresenter::price($saved, $key !== null ? [$key->fingerprint => $key] : [])]);
    }

    /**
     * The price as the edit form would send it, so what is not passed stays.
     *
     * @return array<string, mixed>
     */
    private function current(AiPrice $price, Project $project): array
    {
        return [
            'scope' => $price->scope->value,
            'team_wide' => $price->project_id === null,
            'owner_type' => $price->scope === AiPriceScope::Owner ? $price->scope_type : null,
            'owner_id' => $price->scope === AiPriceScope::Owner ? $price->scope_id : null,
            'key' => $price->scope === AiPriceScope::Key ? AiKey::query()->whereProject($project)->whereFingerprint((string) $price->scope_id)->value('uuid') : null,
            'provider' => $price->provider,
            'model_pattern' => $price->model_pattern,
            'valid_from' => $price->valid_from->toIso8601String(),
            'input' => $price->input,
            'output' => $price->output,
            'cache_read' => $price->cache_read,
            'cache_write' => $price->cache_write,
            'reasoning' => $price->reasoning,
        ];
    }
}
