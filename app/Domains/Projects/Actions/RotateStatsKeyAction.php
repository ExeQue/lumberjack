<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;
use App\Models\User;

/**
 * A new public stats key; the old one stops working at once. Only while public stats are on.
 */
class RotateStatsKeyAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    public function execute(Project $project, User $user): ?string
    {
        if ($project->stats_key === null) {
            return null;
        }

        $project->update(['stats_key' => ProjectKeys::newStatsKey()]);
        $this->audit->execute($user, AdminAction::PublicStatsRotated, $project->name, ['project' => $project->uuid]);

        return $project->stats_key;
    }
}
