<?php

declare(strict_types=1);

namespace Tests\Support\Ai;

use App\Domains\Ai\Models\AiPrice;

/**
 * Prices built in memory, for the price book: a project's price for claude-sonnet-4-5
 * since the start of 2026 unless the attributes say otherwise.
 */
trait BuildsAiPrices
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function aiPrice(array $attributes = []): AiPrice
    {
        return new AiPrice([
            'team_id' => 1,
            'project_id' => 1,
            'scope' => 'project',
            'model_pattern' => 'claude-sonnet-4-5',
            'valid_from' => '2026-01-01 00:00:00',
            'input' => 1,
            'output' => 1,
            ...$attributes,
        ]);
    }
}
