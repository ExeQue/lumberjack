<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders;

use App\Domains\Ai\Eloquent\Builders\Concerns\FiltersByActorAndOwner;
use App\Domains\Ai\Eloquent\Builders\Concerns\FiltersByFingerprint;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\LikePattern;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<AiUsageDaily>
 * @mixin Builder<AiUsageDaily>
 */
class AiUsageDailyBuilder extends Builder
{
    use FiltersByActorAndOwner;
    use FiltersByFingerprint;

    public function whereProject(Project|int $project): self
    {
        return $this->where('project_id', $project instanceof Project ? $project->id : $project);
    }

    public function orWhereProject(Project|int $project): self
    {
        return $this->orWhere('project_id', $project instanceof Project ? $project->id : $project);
    }

    public function whereDaySince(CarbonInterface $since): self
    {
        return $this->where('day', '>=', $since->toDateString());
    }

    public function orWhereDaySince(CarbonInterface $since): self
    {
        return $this->orWhere('day', '>=', $since->toDateString());
    }

    /** Totals of calls naming an owner. */
    public function whereNamesOwner(): self
    {
        return $this->where('owner_type', '!=', '');
    }

    public function orWhereNamesOwner(): self
    {
        return $this->orWhere('owner_type', '!=', '');
    }

    /** Totals of calls naming an actor. */
    public function whereNamesActor(): self
    {
        return $this->where('actor_type', '!=', '');
    }

    public function orWhereNamesActor(): self
    {
        return $this->orWhere('actor_type', '!=', '');
    }

    /** Totals whose owner, as "type:id", holds the term. */
    public function whereOwnerLike(string $term): self
    {
        return $this->whereRaw("(owner_type || ':' || owner_id) ILIKE ?", [LikePattern::contains($term)]);
    }

    public function orWhereOwnerLike(string $term): self
    {
        return $this->orWhereRaw("(owner_type || ':' || owner_id) ILIKE ?", [LikePattern::contains($term)]);
    }

    /** Totals whose actor, as "type:id", holds the term. */
    public function whereActorLike(string $term): self
    {
        return $this->whereRaw("(actor_type || ':' || actor_id) ILIKE ?", [LikePattern::contains($term)]);
    }

    public function orWhereActorLike(string $term): self
    {
        return $this->orWhereRaw("(actor_type || ':' || actor_id) ILIKE ?", [LikePattern::contains($term)]);
    }
}
