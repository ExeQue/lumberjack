<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Data;

use App\Domains\Metrics\Enums\MetricGroup;

/**
 * Which rows a metric is computed from. Route (its URI or its Laravel name), domain,
 * queue, job, host and check (a health check's slug) take a pattern where * stands for
 * anything; without one they match exactly. A filter a metric's data does not carry (a route for a queue's size) is left
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
    ) {
    }
}
