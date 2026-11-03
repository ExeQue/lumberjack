<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Shared\Support\ChunkedDeleter;
use Illuminate\Database\Query\Builder;

/**
 * The administrators' audit log belongs to the whole install, so only the application level sets it.
 */
class PruneAuditLogAction
{
    public function __construct(
        private readonly ResolveRetentionDaysAction $resolve,
        private readonly ChunkedDeleter $deleter,
    ) {
    }

    public function execute(): int
    {
        $days = $this->resolve->execute(RetentionCategory::AuditLog)->days;

        if ($days === null) {
            return 0;
        }

        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');

        return $this->deleter->delete(RetentionCategory::AuditLog->table(), fn (Builder $q) => $q->where('created_at', '<', $cutoff));
    }
}
