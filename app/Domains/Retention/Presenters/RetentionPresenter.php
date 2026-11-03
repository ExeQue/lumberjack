<?php

declare(strict_types=1);

namespace App\Domains\Retention\Presenters;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Actions\ResolveRetentionDaysAction;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Enums\RetentionLevel;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Models\Team;

/**
 * The rows of a retention table at one level: what is stored there, what it would inherit, and what applies.
 */
class RetentionPresenter
{
    public function __construct(private readonly ResolveRetentionDaysAction $resolve)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Team|Project|null $scope = null): array
    {
        $policies = $this->resolve->policiesFor($scope);
        $parent = $scope instanceof Project ? $scope->team : null;
        $categories = $scope === null ? RetentionCategory::cases() : RetentionCategory::forTeams();

        return array_map(function (RetentionCategory $c) use ($scope, $parent, $policies): array {
            $stored = $policies->first(fn (RetentionPolicy $p): bool => $p->category === $c && $this->isOwn($p, $scope));

            return [
                'category' => $c->value,
                'group' => $c->group(),
                'label' => $c->label(),
                'description' => $c->description(),
                'default_days' => $c->defaultDays(),
                'stored' => $stored === null ? null : ['days' => $stored->days],
                'inherited' => $scope === null
                    ? ['days' => $c->defaultDays(), 'source' => RetentionLevel::Default->value, 'capped' => false, 'cap_days' => null, 'cap_level' => null, 'forever_allowed' => true]
                    : $this->resolve->execute($c, $parent, policies: $policies)->toArray(),
                'effective' => $this->resolve->execute($c, $scope, policies: $policies)->toArray(),
            ];
        }, $categories);
    }

    private function isOwn(RetentionPolicy $p, Team|Project|null $scope): bool
    {
        return match (true) {
            $scope instanceof Project => $p->project_id === $scope->id && $p->job_class === null && $p->log_channel_id === null,
            $scope instanceof Team => $p->team_id === $scope->id && $p->project_id === null,
            default => $p->team_id === null && $p->project_id === null,
        };
    }
}
