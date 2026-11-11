<?php

declare(strict_types=1);

namespace App\Domains\SourceMaps\Eloquent\Builders;

use App\Domains\Projects\Models\Project;
use App\Domains\SourceMaps\Models\SourceMap;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<SourceMap>
 *
 * @mixin Builder<SourceMap>
 */
final class SourceMapBuilder extends Builder
{
    public function whereProject(Project|int $project): self
    {
        return $this->where('project_id', $project instanceof Project ? $project->id : $project);
    }

    public function orWhereProject(Project|int $project): self
    {
        return $this->orWhere('project_id', $project instanceof Project ? $project->id : $project);
    }

    /**
     * Maps of these bundle paths, as stored (normalized).
     *
     * @param  array<array-key, string>  $paths
     */
    public function wherePathIn(array $paths): self
    {
        return $this->whereIn('path', $paths);
    }

    /**
     * @param  array<array-key, string>  $paths
     */
    public function orWherePathIn(array $paths): self
    {
        return $this->orWhereIn('path', $paths);
    }
}
