<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Eloquent\Builders;

use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<NotificationChannel>
 */
class NotificationChannelBuilder extends Builder
{
    /** Shared by the whole team. */
    public function whereTeamWide(): self
    {
        return $this->whereNull('project_id');
    }

    public function orWhereTeamWide(): self
    {
        return $this->orWhereNull('project_id');
    }

    /** This project's own channels. */
    public function whereForProject(Project $project): self
    {
        return $this->where('project_id', $project->id);
    }

    public function orWhereForProject(Project $project): self
    {
        return $this->orWhere('project_id', $project->id);
    }

    /** What the project's rules may send to: the team's shared channels and its own. */
    public function whereAvailableTo(Project $project): self
    {
        return $this->where('team_id', $project->team_id)
            ->where(fn (self $q) => $q->whereTeamWide()->orWhereForProject($project));
    }

    public function orWhereAvailableTo(Project $project): self
    {
        return $this->orWhere(fn (self $q) => $q->whereAvailableTo($project));
    }
}
