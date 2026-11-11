<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Support\Facades\DB;

/**
 * A map goes when it is older than 90 days and belongs to none of the project's
 * last 10 releases: errors from an old build still deployed somewhere keep their map.
 */
class PruneSourceMapsAction
{
    public const int KEEP_DAYS = 90;

    public const int KEEP_RELEASES = 10;

    public function __construct(
        private readonly DeleteSourceMapAction $deleteSourceMapAction,
    ) {
    }

    /**
     * @return int maps deleted
     */
    public function execute(Project $project): int
    {
        $releases = DB::table('project_releases')->where('project_id', $project->id)
            ->orderByDesc('first_seen_at')->limit(self::KEEP_RELEASES)->pluck('release')->all();

        $deleted = 0;

        SourceMap::query()
            ->whereProject($project)
            ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS))
            ->where(fn ($q) => $q->whereNull('release')->orWhereNotIn('release', $releases))
            ->eachById(function (SourceMap $map) use (&$deleted): void {
                $this->deleteSourceMapAction->execute($map);
                $deleted++;
            });

        return $deleted;
    }
}
