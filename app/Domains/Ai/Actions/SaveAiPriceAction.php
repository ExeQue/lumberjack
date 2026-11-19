<?php

declare(strict_types=1);

namespace App\Domains\Ai\Actions;

use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds a price, or changes one, from the project's prices page. A price for the whole
 * team has no project; one for an owner or a key always has this one, since a key's
 * fingerprint is the project's own.
 */
class SaveAiPriceAction
{
    /**
     * @param  array{team_wide?: bool, scope_type?: string|null, scope_id?: string|null, provider?: string|null, model_pattern: string, valid_from: CarbonImmutable, input: float, output: float, cache_read?: float|null, cache_write?: float|null, reasoning?: float|null}  $values
     */
    public function execute(Project $project, ?AiPrice $price, AiPriceScope $scope, array $values): AiPrice
    {
        $teamWide = $scope === AiPriceScope::Project && ($values['team_wide'] ?? false);

        // The team as it is now: a move to another team takes the project's prices along.
        return DB::transaction(function () use ($project, $price, $scope, $values, $teamWide): AiPrice {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            // A price never changes team: one the project's team no longer has is gone from here.
            abort_if($price !== null && $price->team_id !== $project->team_id, 404);

            $price ??= new AiPrice(['team_id' => $project->team_id]);
            $price->fill([
                'project_id' => $teamWide ? null : $project->id,
                'scope' => $scope,
                'scope_type' => $scope === AiPriceScope::Owner ? $values['scope_type'] ?? null : null,
                'scope_id' => $scope === AiPriceScope::Project ? null : $values['scope_id'] ?? null,
                'provider' => ($values['provider'] ?? null) !== null ? strtolower((string) $values['provider']) : null,
                'model_pattern' => $values['model_pattern'],
                'valid_from' => $values['valid_from'],
                'input' => $values['input'],
                'output' => $values['output'],
                'cache_read' => $values['cache_read'] ?? null,
                'cache_write' => $values['cache_write'] ?? null,
                'reasoning' => $values['reasoning'] ?? null,
            ])->save();

            return $price;
        });
    }
}
