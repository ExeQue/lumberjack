<?php

declare(strict_types=1);

namespace App\Domains\Commands\Eloquent\Builders;

use App\Domains\Commands\Models\CommandRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @extends Builder<CommandRun>
 */
class CommandRunBuilder extends Builder
{
    /** The newest run of each command in its project. */
    public function whereLatestOfItsCommand(): self
    {
        return $this->whereNotExists($this->laterRun(...));
    }

    public function orWhereLatestOfItsCommand(): self
    {
        return $this->orWhereNotExists($this->laterRun(...));
    }

    private function laterRun(QueryBuilder $query): void
    {
        $query->selectRaw('1')
            ->from('command_runs', 'later')
            ->whereColumn('later.project_id', 'command_runs.project_id')
            ->whereColumn('later.name', 'command_runs.name')
            ->whereColumn('later.id', '>', 'command_runs.id');
    }
}
