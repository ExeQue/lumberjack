<?php

declare(strict_types=1);

namespace App\Domains\Ai\Eloquent\Builders\Concerns;

/**
 * Rows caused by one actor, or made with the keys of one owner.
 */
trait FiltersByActorAndOwner
{
    public function whereActor(string $type, string $id): self
    {
        return $this->where(fn (self $q) => $q->where('actor_type', $type)->where('actor_id', $id));
    }

    public function orWhereActor(string $type, string $id): self
    {
        return $this->orWhere(fn (self $q) => $q->where('actor_type', $type)->where('actor_id', $id));
    }

    public function whereOwner(string $type, string $id): self
    {
        return $this->where(fn (self $q) => $q->where('owner_type', $type)->where('owner_id', $id));
    }

    public function orWhereOwner(string $type, string $id): self
    {
        return $this->orWhere(fn (self $q) => $q->where('owner_type', $type)->where('owner_id', $id));
    }
}
