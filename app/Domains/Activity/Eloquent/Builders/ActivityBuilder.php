<?php

declare(strict_types=1);

namespace App\Domains\Activity\Eloquent\Builders;

use App\Domains\Activity\Models\Activity;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<Activity>
 */
class ActivityBuilder extends Builder
{
    public function whereEvent(string $group, string $name): self
    {
        return $this->where(fn (self $q) => $q->where('group', $group)->where('name', $name));
    }

    public function orWhereEvent(string $group, string $name): self
    {
        return $this->orWhere(fn (self $q) => $q->where('group', $group)->where('name', $name));
    }

    public function whereActor(string $type, string $id): self
    {
        return $this->where(fn (self $q) => $q->where('actor_type', $type)->where('actor_id', $id));
    }

    public function orWhereActor(string $type, string $id): self
    {
        return $this->orWhere(fn (self $q) => $q->where('actor_type', $type)->where('actor_id', $id));
    }

    /**
     * Done to this subject, among any others.
     */
    /**
     * Done by any of these actors.
     *
     * @param  list<array{type: string, id: string}>  $actors
     */
    public function whereActorIn(array $actors): self
    {
        return $this->where(function (self $q) use ($actors): void {
            foreach ($actors as $actor) {
                $q->orWhereActor($actor['type'], $actor['id']);
            }
        });
    }

    /**
     * @param  list<array{type: string, id: string}>  $actors
     */
    public function orWhereActorIn(array $actors): self
    {
        return $this->orWhere(fn (self $q) => $q->whereActorIn($actors));
    }

    /**
     * Done to any of these subjects.
     *
     * @param  list<array{type: string, id: string}>  $subjects
     */
    public function whereSubjectIn(array $subjects): self
    {
        return $this->where(function (self $q) use ($subjects): void {
            foreach ($subjects as $subject) {
                $q->orWhereSubject($subject['type'], $subject['id']);
            }
        });
    }

    /**
     * @param  list<array{type: string, id: string}>  $subjects
     */
    public function orWhereSubjectIn(array $subjects): self
    {
        return $this->orWhere(fn (self $q) => $q->whereSubjectIn($subjects));
    }

    public function whereSubject(string $type, string $id): self
    {
        return $this->whereJsonContains('subjects', [['type' => $type, 'id' => $id]]);
    }

    public function orWhereSubject(string $type, string $id): self
    {
        return $this->orWhereJsonContains('subjects', [['type' => $type, 'id' => $id]]);
    }
}
