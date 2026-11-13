<?php

declare(strict_types=1);

namespace App\Domains\Activity\Eloquent\Builders;

use App\Domains\Activity\Models\ActivityLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<ActivityLabel>
 */
class ActivityLabelBuilder extends Builder
{
    /**
     * @param  list<int>  $projectIds
     */
    public function whereProjectIn(array $projectIds): self
    {
        return $this->whereIn('project_id', $projectIds);
    }

    /**
     * @param  list<int>  $projectIds
     */
    public function orWhereProjectIn(array $projectIds): self
    {
        return $this->orWhereIn('project_id', $projectIds);
    }

    /**
     * What groups and activities are shown as: groups by their name, activities by group:name.
     * Across several projects the first label given wins.
     *
     * @return array{groups: array<string, string>, activities: array<string, string>}
     */
    public function toMap(): array
    {
        $map = ['groups' => [], 'activities' => []];

        foreach ($this->orderBy('id')->get(['group', 'name', 'label']) as $label) {
            if ($label->name === '') {
                $map['groups'][$label->group] ??= $label->label;
            } else {
                $map['activities'][$label->group.':'.$label->name] ??= $label->label;
            }
        }

        return $map;
    }
}
