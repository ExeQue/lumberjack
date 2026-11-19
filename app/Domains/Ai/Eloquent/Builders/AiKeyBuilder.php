<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders;

use App\Domains\Ai\Eloquent\Builders\Concerns\FiltersByFingerprint;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Support\LikePattern;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Builder<AiKey>
 * @mixin Builder<AiKey>
 */
class AiKeyBuilder extends Builder
{
    use FiltersByFingerprint;

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

    /** Keys known by this name, label or (8 characters) short id, exactly. */
    public function whereNamed(string $name): self
    {
        return $this->where(fn (self $keys) => $keys->named($name));
    }

    public function orWhereNamed(string $name): self
    {
        return $this->orWhere(fn (self $keys) => $keys->named($name));
    }

    private function named(string $name): self
    {
        return $this->where('name', $name)->orWhere('label', $name)->when(strlen($name) === 8)->orWhereShortId($name);
    }

    /** Keys whose short id, the first 8 characters of the fingerprint, is this one. */
    public function whereShortId(string $shortId): self
    {
        return $this->where('fingerprint', 'like', addcslashes(strtolower($shortId), '\\%_').'%');
    }

    public function orWhereShortId(string $shortId): self
    {
        return $this->orWhere('fingerprint', 'like', addcslashes(strtolower($shortId), '\\%_').'%');
    }

    public function whereSeenSince(CarbonInterface $since): self
    {
        return $this->where('last_seen_at', '>=', $since);
    }

    public function orWhereSeenSince(CarbonInterface $since): self
    {
        return $this->orWhere('last_seen_at', '>=', $since);
    }

    /**
     * Keys whose name, label, short id, provider or owner ("type:id") holds the term; * stands
     * for anything, % and _ for themselves.
     */
    public function whereSearch(string $term): self
    {
        return $this->where(fn (self $q) => $q->searching($term));
    }

    public function orWhereSearch(string $term): self
    {
        return $this->orWhere(fn (self $q) => $q->searching($term));
    }

    /** Keys a customer brought: with an owner. */
    public function whereOwned(): self
    {
        return $this->whereNotNull('owner_type');
    }

    public function orWhereOwned(): self
    {
        return $this->orWhereNotNull('owner_type');
    }

    /** Owned keys whose owner, as "type:id", holds the term. */
    public function whereOwnerLike(string $term): self
    {
        return $this->whereRaw("(owner_type || ':' || owner_id) ILIKE ?", [LikePattern::contains($term)]);
    }

    public function orWhereOwnerLike(string $term): self
    {
        return $this->orWhereRaw("(owner_type || ':' || owner_id) ILIKE ?", [LikePattern::contains($term)]);
    }

    public function whereOwner(string $type, string $id): self
    {
        return $this->where(fn (self $q) => $q->where('owner_type', $type)->where('owner_id', $id));
    }

    public function orWhereOwner(string $type, string $id): self
    {
        return $this->orWhere(fn (self $q) => $q->where('owner_type', $type)->where('owner_id', $id));
    }

    private function searching(string $term): self
    {
        $pattern = LikePattern::contains($term);

        return $this->whereRaw("CONCAT_WS(' ', name, label, LEFT(fingerprint, 8), provider, owner_type || ':' || owner_id) ILIKE ?", [$pattern]);
    }
}
