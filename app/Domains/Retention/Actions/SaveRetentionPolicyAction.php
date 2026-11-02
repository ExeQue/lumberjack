<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;

class SaveRetentionPolicyAction
{
    /**
     * A null number of days removes the setting, so it inherits again.
     *
     * @param  array{team_id?: int|null, project_id?: int|null, job_class?: string|null, log_channel_id?: int|null}  $scope
     */
    public function execute(array $scope, RetentionCategory $category, ?int $days): void
    {
        $match = [
            'team_id' => $scope['team_id'] ?? null,
            'project_id' => $scope['project_id'] ?? null,
            'job_class' => $scope['job_class'] ?? null,
            'log_channel_id' => $scope['log_channel_id'] ?? null,
            'category' => $category->value,
        ];

        if ($days === null) {
            RetentionPolicy::query()->where($match)->delete();

            return;
        }

        RetentionPolicy::query()->updateOrCreate($match, ['days' => $days]);
    }
}
