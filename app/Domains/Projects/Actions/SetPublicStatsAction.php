<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Administration\Actions\RecordAdminAuditAction;
use App\Domains\Administration\Enums\AdminAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Support\ProjectKeys;
use App\Models\User;

/**
 * Turns public stats on (with a new key), off (the key stops working at once), or
 * changes whether they include issue titles. Every change goes in the audit log.
 */
class SetPublicStatsAction
{
    public function __construct(
        private readonly RecordAdminAuditAction $audit,
    ) {
    }

    public function execute(Project $project, User $user, bool $enabled, bool $includeTitles): Project
    {
        $wasEnabled = $project->stats_key !== null;

        if (! $enabled) {
            if ($wasEnabled) {
                $project->update(['stats_key' => null, 'stats_include_titles' => false]);
                $this->audit->execute($user, AdminAction::PublicStatsDisabled, $project->name, ['project' => $project->uuid]);
            }

            return $project;
        }

        if ($wasEnabled && $project->stats_include_titles === $includeTitles) {
            return $project;
        }

        $project->update([
            'stats_key' => $project->stats_key ?? ProjectKeys::newStatsKey(),
            'stats_include_titles' => $includeTitles,
        ]);
        $this->audit->execute($user, $wasEnabled ? AdminAction::PublicStatsChanged : AdminAction::PublicStatsEnabled, $project->name, ['project' => $project->uuid, 'include_titles' => $includeTitles]);

        return $project;
    }
}
