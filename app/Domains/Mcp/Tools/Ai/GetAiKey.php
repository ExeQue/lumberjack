<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Ai;

use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Presenters\AiBudgetPresenter;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Ai\Queries\AiBudgetQuery;
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
class GetAiKey extends Tool
{
    use UsesAiUsage;

    protected string $name = 'get_ai_key';

    protected string $description = 'One AI key: its name, label, owner and who pays for it (payer as set, null to inherit; effective_payer as charged), its usage in the period against the period before, the price each model it used is charged at now (null for none), the budgets that count it, and its latest calls, newest first.';

    public function __construct(
        private readonly AiUsagePresenter $usage,
        private readonly AiBudgetQuery $budgets,
        private readonly AiBudgetPresenter $budgetPresenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'key' => $this->keyArgument($schema),
            'period' => $this->periodArgument($schema),
            'environment' => $schema->string(),
            'only_failed' => $schema->boolean()->description('Only the failed calls.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Calls, defaults to 20.'),
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

        $period = $this->period($request);
        $environment = $this->environment($request);
        $book = AiPriceBook::for($project);
        $now = now()->toImmutable();
        [$from] = $this->usage->window($period, AiCostQuery::HOURLY, $now);

        $calls = AiCall::query()
            ->where('project_id', $project->id)
            ->whereFingerprint($key->fingerprint)
            ->when($environment !== null)->where('environment', $environment)
            ->when($request->get('only_failed') === true)->whereFailed()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(min(100, max(1, (int) $request->get('limit', 20))))
            ->get();

        return Response::json([
            'key' => [
                ...AiUsagePresenter::key($key),
                'first_seen_at' => $key->first_seen_at->toIso8601String(),
                'last_seen_at' => $key->last_seen_at->toIso8601String(),
                'effective_payer' => $key->effectivePayer($project->ai_default_payer)->value,
            ],
            'default_payer' => $project->ai_default_payer->value,
            'period' => $period->value,
            'summary' => $this->usage->summary($project, $period, $environment, AiCostQuery::HOURLY, ['fingerprint' => $key->fingerprint], $book, $now),
            'prices' => $this->usage->keyPrices($project, $key, $from, $book, $environment),
            'budgets' => array_map($this->withStatus(...), $this->budgetPresenter->presentAll($this->budgets->covering($project, AiBudgetScope::Key, null, $key->fingerprint), $book)),
            'calls' => $calls->map(AiUsagePresenter::call(...))->all(),
        ]);
    }
}
