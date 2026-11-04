<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Shared\Support\ChunkedDeleter;
use Illuminate\Database\Query\Builder;

/**
 * Data of the whole install or of people rather than of a team (the administrators'
 * audit log, everyone's notification history): only the application level sets it.
 */
class PruneApplicationDataAction
{
    public function __construct(
        private readonly ResolveRetentionDaysAction $resolve,
        private readonly ChunkedDeleter $deleter,
    ) {
    }

    /**
     * @return array<string, int> rows deleted per category
     */
    public function execute(): array
    {
        $deleted = [];

        foreach (RetentionCategory::cases() as $category) {
            if (! $category->applicationOnly()) {
                continue;
            }

            $days = $this->resolve->execute($category)->days;

            if ($days === null) {
                $deleted[$category->value] = 0;

                continue;
            }

            $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
            $deleted[$category->value] = $this->deleter->delete($category->table(), fn (Builder $q) => $q->where($category->timeColumn(), '<', $cutoff));
        }

        return $deleted;
    }
}
