<?php

declare(strict_types=1);

namespace App\Domains\Issues\Actions;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Projects\Models\Project;
use App\Models\User;

/**
 * Adds one label to, or removes it from, many issues from the issues list.
 */
class BulkLabelIssuesAction
{
    public function __construct(
        private readonly SetIssueLabelsAction $labels,
    ) {
    }

    /**
     * @param  list<string>  $uuids
     * @return int issues it changed
     */
    public function execute(Project $project, array $uuids, string $label, bool $remove, User $by): int
    {
        $changed = 0;

        foreach (Issue::query()->with('labels')->where('project_id', $project->id)->whereIn('uuid', $uuids)->get() as $issue) {
            $names = $issue->labels->pluck('name');
            $has = $names->contains(fn (string $name): bool => mb_strtolower($name) === mb_strtolower(trim($label)));

            if ($has !== $remove || (! $remove && $names->count() >= Label::MAX_PER_ISSUE)) {
                continue;
            }

            $this->labels->execute($issue, array_values(($remove
                ? $names->reject(fn (string $name): bool => mb_strtolower($name) === mb_strtolower(trim($label)))
                : $names->push($label))->all()), $by);
            $changed++;
        }

        return $changed;
    }
}
