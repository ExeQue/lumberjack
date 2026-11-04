<?php

declare(strict_types=1);

namespace App\Domains\Projects\Queries;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Environments seen in the last week, for the environment filter.
 */
class ProjectEnvironmentsQuery
{
    public const int CACHE_SECONDS = 300;

    /** Environments read per project from each table. */
    public const int LIMIT = 20;

    /**
     * @return list<string>
     */
    public function get(Project $project): array
    {
        return Cache::remember("project:{$project->id}:environments", self::CACHE_SECONDS, fn (): array => $this->query([$project->id]));
    }

    /**
     * The environments of several projects together, in two queries rather than two per project.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<string>
     */
    public function forProjects(Collection $projects): array
    {
        return $projects->isEmpty() ? [] : $this->query($projects->modelKeys());
    }

    /**
     * @param  array<int, int|string>  $projectIds
     * @return list<string>
     */
    private function query(array $projectIds): array
    {
        $distinct = fn (string $table) => DB::table($table)
            ->whereIn('project_id', $projectIds)
            ->where('occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('environment')
            ->distinct()
            ->limit(self::LIMIT * count($projectIds))
            ->pluck('environment');

        return array_values($distinct('request_runs')->merge($distinct('occurrences'))->map(fn (mixed $environment): string => (string) $environment)->unique()->sort()->all());
    }
}
