<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Eloquent\Builders;

use App\Domains\Alerts\Models\AiErrorRate;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<AiErrorRate>
 * @mixin Builder<AiErrorRate>
 */
class AiErrorRateBuilder extends Builder
{
    public function whereProject(Project|int $project): self
    {
        return $this->where('project_id', $project instanceof Project ? $project->id : $project);
    }

    public function orWhereProject(Project|int $project): self
    {
        return $this->orWhere('project_id', $project instanceof Project ? $project->id : $project);
    }

    public function whereRule(AlertRule $rule): self
    {
        return $this->where('alert_rule_id', $rule->id);
    }

    public function orWhereRule(AlertRule $rule): self
    {
        return $this->orWhere('alert_rule_id', $rule->id);
    }

    public function whereNotRule(AlertRule $rule): self
    {
        return $this->where('alert_rule_id', '!=', $rule->id);
    }

    public function orWhereNotRule(AlertRule $rule): self
    {
        return $this->orWhere('alert_rule_id', '!=', $rule->id);
    }
}
