<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Models\AiPrice;
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
class ListAiPrices extends Tool
{
    use UsesAiUsage;

    protected string $name = 'list_ai_prices';

    protected string $description = 'The AI prices that apply to the project, its own and its team\'s (team_wide), per million tokens in the team\'s currency: each with its scope (project, owner or key), model pattern (* is a wildcard), provider, valid_from and prices, by model pattern, the newest first. Also who pays for a key that does not say (default_payer).';

    public function schema(JsonSchema $schema): array
    {
        return ['project' => $this->projectArgument($schema)];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $prices = AiPrice::query()->whereAppliesTo($project)->orderBy('model_pattern')->orderByDesc('valid_from')->get();
        // Only the keys the prices name, shown by uuid and short id like everywhere else.
        $keys = $project->aiKeys()
            ->whereIn('fingerprint', $prices->where('scope', AiPriceScope::Key)->pluck('scope_id')->filter()->unique()->values()->all())
            ->get()->keyBy('fingerprint')->all();

        return Response::json([
            'currency' => $project->team->currency,
            'default_payer' => $project->ai_default_payer->value,
            'prices' => $prices->map(fn (AiPrice $price): array => AiUsagePresenter::price($price, $keys))->values()->all(),
        ]);
    }
}
