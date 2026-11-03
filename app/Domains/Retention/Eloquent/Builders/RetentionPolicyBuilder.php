<?php

declare(strict_types=1);

namespace App\Domains\Retention\Eloquent\Builders;

use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<RetentionPolicy>
 */
class RetentionPolicyBuilder extends Builder
{
    /** Rows of the whole install: neither team nor project. */
    public function whereApplication(): self
    {
        return $this->where(fn (self $q) => $q->whereNull('team_id')->whereNull('project_id'));
    }

    public function orWhereApplication(): self
    {
        return $this->orWhere(fn (self $q) => $q->whereNull('team_id')->whereNull('project_id'));
    }

    /** A team's defaults, not its projects' rows. */
    public function whereTeamDefault(int $teamId): self
    {
        return $this->where(fn (self $q) => $q->where('team_id', $teamId)->whereNull('project_id'));
    }

    public function orWhereTeamDefault(int $teamId): self
    {
        return $this->orWhere(fn (self $q) => $q->where('team_id', $teamId)->whereNull('project_id'));
    }

    public function whereProject(int $projectId): self
    {
        return $this->where('project_id', $projectId);
    }

    public function orWhereProject(int $projectId): self
    {
        return $this->orWhere('project_id', $projectId);
    }
}
