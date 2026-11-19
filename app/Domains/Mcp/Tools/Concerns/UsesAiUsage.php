<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\Ai\Http\Controllers\AiUsageController;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * AI keys, budgets and prices of a project. Keys are named by uuid or short id only:
 * their fingerprints never leave Lumberjack.
 */
trait UsesAiUsage
{
    use UsesMcpContext;

    protected function periodArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->enum(array_column(Period::cases(), 'value'))->description('Defaults to 30d.');
    }

    protected function period(Request $request): Period
    {
        return Period::tryFrom((string) $request->get('period')) ?? AiUsageController::DEFAULT_PERIOD;
    }

    protected function environment(Request $request): ?string
    {
        $environment = trim((string) $request->get('environment', ''));

        return $environment !== '' ? $environment : null;
    }

    protected function keyArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->description('The key\'s uuid or its 8 character short id; see list_ai_spenders.')->required();
    }

    /**
     * The key the request names in the project, by uuid or short id.
     */
    protected function aiKey(Request $request, Project $project): AiKey|Response
    {
        $given = trim((string) $request->get('key'));
        $query = AiKey::query()->whereProject($project);

        $keys = match (true) {
            Str::isUuid($given) => $query->whereUuid($given)->get(),
            preg_match('/^[0-9a-f]{8}$/i', $given) === 1 => $query->whereShortId($given)->limit(2)->get(),
            default => collect(),
        };

        if ($keys->count() > 1) {
            return Response::error(__('mcp.ai.ambiguous_key'));
        }

        return $keys->first() ?? Response::error(__('mcp.ai.unknown_key', ['project' => $project->slug]));
    }

    /**
     * The budget the request names in the project.
     */
    protected function aiBudget(Request $request, Project $project): AiBudget|Response
    {
        $uuid = (string) $request->get('budget');
        $budget = Str::isUuid($uuid) ? AiBudget::query()->whereProject($project)->whereUuid($uuid)->with('project.team')->first() : null;

        return $budget ?? Response::error(__('mcp.ai.unknown_budget', ['project' => $project->slug]));
    }

    /**
     * The price the request names among those that apply to the project.
     */
    protected function aiPrice(Request $request, Project $project): AiPrice|Response
    {
        $uuid = (string) $request->get('price');
        $price = Str::isUuid($uuid) ? AiPrice::query()->whereAppliesTo($project)->where('uuid', $uuid)->first() : null;

        return $price ?? Response::error(__('mcp.ai.unknown_price', ['project' => $project->slug]));
    }

    /**
     * Null when the token's maker may do $ability on the price from this project's prices
     * page, as AiPricePolicy decides; else the refusal to return.
     */
    protected function authorizePrice(string $ability, AiPrice $price, Project $project): ?Response
    {
        return $this->context()->token()->allows($ability, [$price, $project])
            ? null
            : Response::error(__('mcp.ai.price_denied'));
    }

    /**
     * A presented budget with where it stands: over, projected_over (on course to go over
     * by the period's end) or ok.
     *
     * @param  array<string, mixed>  $budget  as AiBudgetPresenter shows it
     * @return array<string, mixed>
     */
    protected function withStatus(array $budget): array
    {
        /** @var array{spent: float, amount: float, projected: float} $usage */
        $usage = $budget['usage'];

        return [
            ...$budget,
            'status' => match (true) {
                $usage['spent'] >= $usage['amount'] => 'over',
                $usage['projected'] > $usage['amount'] => 'projected_over',
                default => 'ok',
            },
        ];
    }
}
