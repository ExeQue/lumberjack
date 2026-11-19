<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Data;

use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Metrics\Enums\MetricGroup;

/**
 * Which rows a metric is computed from. Route (its URI or its Laravel name), domain,
 * queue, job, host, check (a health check's slug), activity (group:name), AI provider and
 * model take a pattern where * stands for anything; without one they match exactly. An AI
 * key is its name or short id, an owner or actor type:id, and payer only narrows ai_cost. A filter a metric's data does not carry (a route for a queue's size) is left
 * out for that metric.
 */
final readonly class MetricFilter
{
    /**
     * @param  list<int>  $projectIds
     */
    public function __construct(
        public array $projectIds,
        public ?string $environment = null,
        public ?string $route = null,
        public ?string $queue = null,
        public ?string $job = null,
        public ?string $host = null,
        public ?string $release = null,
        public ?MetricGroup $groupBy = null,
        public ?string $check = null,
        public ?string $domain = null,
        public ?string $activity = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $key = null,
        public ?string $owner = null,
        public ?string $actor = null,
        public ?AiPayer $payer = null,
    ) {
    }
}
