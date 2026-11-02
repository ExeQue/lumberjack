<?php

declare(strict_types=1);

namespace App\Domains\Projects\Queries;

use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Environments seen in the last week, for the environment filter.
 */
class ProjectEnvironmentsQuery
{
    public const int CACHE_SECONDS = 300;

    /**
     * @return list<string>
     */
    public function get(Project $project): array
    {
        $environments = Cache::remember("project:{$project->id}:environments", self::CACHE_SECONDS, fn (): array => DB::table('request_runs')
            ->where('project_id', $project->id)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('environment')
            ->distinct()
            ->limit(20)
            ->pluck('environment')
            ->merge(DB::table('occurrences')->where('project_id', $project->id)->where('occurred_at', '>=', now()->subDays(7))->whereNotNull('environment')->distinct()->limit(20)->pluck('environment'))
            ->unique()
            ->sort()
            ->values()
            ->all());

        return array_values(array_map('strval', (array) $environments));
    }
}
