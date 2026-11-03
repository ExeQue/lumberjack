<?php

declare(strict_types=1);

namespace Tests\Support\Performance;

use App\Domains\Performance\Models\RequestCount;
use App\Domains\Projects\Models\Project;
use DateTimeInterface;

trait CreatesRequestCounts
{
    /**
     * One per-minute request count row, as the SDK's request_counts events add up to.
     */
    public function createRequestCount(Project $project, int $count, int $status = 200, string $route = '/orders', ?DateTimeInterface $minute = null, int $durationUs = 1_000_000, string $domain = ''): RequestCount
    {
        return RequestCount::query()->create([
            'project_id' => $project->id, 'environment' => 'production', 'minute' => $minute ?? now()->subHour()->startOfMinute(),
            'method' => 'GET', 'domain' => $domain, 'route' => $route, 'status_code' => $status, 'count' => $count, 'duration_us' => $durationUs,
        ]);
    }
}
