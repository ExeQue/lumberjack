<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Ai\Support\AiPriceBook;
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
class GetAiUsage extends Tool
{
    use UsesAiUsage;

    protected string $name = 'get_ai_usage';

    protected string $description = 'What the project\'s AI calls used and cost in the period: calls, errors, tokens, cost paid by us (cost_ours) and by customers (cost_customer), the same for the period before under "previous", and a breakdown per model, costliest first. Cost is in the team\'s currency. "warning" is set when tokens have no price, so their cost is missing; add one with save_ai_price.';

    public function __construct(
        private readonly AiUsagePresenter $usage,
        private readonly AiCostQuery $costs,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'period' => $this->periodArgument($schema),
            'environment' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $period = $this->period($request);
        $environment = $this->environment($request);
        $book = AiPriceBook::for($project);
        $now = now()->toImmutable();
        $summary = $this->usage->summary($project, $period, $environment, book: $book, now: $now);
        [$from] = $this->usage->window($period, AiCostQuery::HOURLY, $now);

        $models = array_map(
            fn (array $row): array => ['provider' => $row['provider'], 'model' => $row['model'], ...AiUsagePresenter::totals($row)],
            $this->costs->get($project, AiCostQuery::HOURLY, $from, null, $environment, ['model'], book: $book),
        );
        // ponytail: a project uses a handful of models, so these are sorted here.
        usort($models, fn (array $a, array $b): int => [$b['cost'], $b['tokens']] <=> [$a['cost'], $a['tokens']]);

        return Response::json([
            'project' => $project->slug,
            'period' => $period->value,
            'environment' => $environment,
            'currency' => $project->team->currency,
            'summary' => $summary,
            'models' => $models,
            'warning' => $summary['unpriced_tokens'] > 0 ? __('ui/ai.kpis.unpriced', ['tokens' => number_format($summary['unpriced_tokens'])]) : null,
        ]);
    }
}
