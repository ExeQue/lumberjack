<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders;

use App\Domains\Ai\Models\AiPrice;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<AiPrice>
 * @mixin Builder<AiPrice>
 */
class AiPriceBuilder extends Builder
{
    /**
     * The project's own prices and those of its whole team.
     */
    public function whereAppliesTo(Project $project): self
    {
        return $this->where(fn (self $q) => $q->where('team_id', $project->team_id)->where(fn (self $p) => $p->whereNull('project_id')->orWhere('project_id', $project->id)));
    }

    public function orWhereAppliesTo(Project $project): self
    {
        return $this->orWhere(fn (self $q) => $q->whereAppliesTo($project));
    }
}
