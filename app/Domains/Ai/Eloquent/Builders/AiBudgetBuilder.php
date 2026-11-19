<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders;

use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Builder<AiBudget>
 * @mixin Builder<AiBudget>
 */
class AiBudgetBuilder extends Builder
{
    public function whereProject(Project|int $project): self
    {
        return $this->where('project_id', $project instanceof Project ? $project->id : $project);
    }

    public function orWhereProject(Project|int $project): self
    {
        return $this->orWhere('project_id', $project instanceof Project ? $project->id : $project);
    }

    /** @param  list<int>|Collection<int, Project>  $projects */
    public function whereProjectIn(array|Collection $projects): self
    {
        return $this->whereIn('project_id', $projects instanceof Collection ? $projects->modelKeys() : $projects);
    }

    /** @param  list<int>|Collection<int, Project>  $projects */
    public function orWhereProjectIn(array|Collection $projects): self
    {
        return $this->orWhereIn('project_id', $projects instanceof Collection ? $projects->modelKeys() : $projects);
    }

    public function whereUuid(string $uuid): self
    {
        return $this->where('uuid', $uuid);
    }

    public function orWhereUuid(string $uuid): self
    {
        return $this->orWhere('uuid', $uuid);
    }

    /**
     * Budgets counting one key (by fingerprint, without a type), key owner or actor, among
     * the others they count.
     */
    public function whereCounting(AiBudgetScope $scope, ?string $type, string $id): self
    {
        return $this->where(fn (self $q) => $q->counting($scope, $type, $id));
    }

    public function orWhereCounting(AiBudgetScope $scope, ?string $type, string $id): self
    {
        return $this->orWhere(fn (self $q) => $q->counting($scope, $type, $id));
    }

    private function counting(AiBudgetScope $scope, ?string $type, string $id): self
    {
        return $this->where('scope', $scope)->whereHas('targets', fn (Builder $targets) => $scope === AiBudgetScope::Key
            ? $targets->where('fingerprint', $id)
            : $targets->where('ref_type', (string) $type)->where('ref_id', $id));
    }

    /** Budgets in the team's currency, which a move to a team of another currency would misread. */
    public function whereCost(): self
    {
        return $this->where('basis', AiBudgetBasis::Cost);
    }

    public function orWhereCost(): self
    {
        return $this->orWhere('basis', AiBudgetBasis::Cost);
    }
}
