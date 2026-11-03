<?php

declare(strict_types=1);

namespace App\Domains\Retention\Actions;

use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How much of each data type is stored and how old its oldest row is, cheap enough for a
 * settings page: counts stop at COUNT_CAP, and the whole install only gets the planner's estimate.
 */
class MeasureRetentionAction
{
    public const int COUNT_CAP = 100_000;

    /**
     * @param  list<RetentionCategory>  $categories
     * @return array<string, array{rows: int, rows_more: bool, rows_estimated: bool, oldest_at: string|null}>
     */
    public function execute(array $categories, Team|Project|null $scope = null): array
    {
        $projectIds = match (true) {
            $scope instanceof Project => [$scope->id],
            $scope instanceof Team => array_values(array_map(intval(...), $scope->projects()->pluck('id')->all())),
            default => null,
        };

        $stats = [];

        foreach ($categories as $category) {
            $stats[$category->value] = $projectIds === null && ! $category->applicationOnly()
                ? ['rows' => $this->estimate($category->table()), 'rows_more' => false, 'rows_estimated' => true, 'oldest_at' => null]
                : $this->measure($category, $projectIds);
        }

        return $stats;
    }

    /**
     * @param  list<int>|null  $projectIds  null for the whole table
     * @return array{rows: int, rows_more: bool, rows_estimated: bool, oldest_at: string|null}
     */
    private function measure(RetentionCategory $category, ?array $projectIds): array
    {
        $rows = DB::query()->fromSub($this->query($category, $projectIds)->selectRaw('1')->limit(self::COUNT_CAP + 1), 'capped')->count();

        // One index lookup per project: a min over several projects at once would scan them.
        $oldest = collect($projectIds === null ? [null] : array_map(fn (int $id): array => [$id], $projectIds))
            ->map(fn (?array $ids) => $this->query($category, $ids)->min($category->timeColumn()))
            ->filter()
            ->min();

        return [
            'rows' => min($rows, self::COUNT_CAP),
            'rows_more' => $rows > self::COUNT_CAP,
            'rows_estimated' => false,
            'oldest_at' => $oldest !== null ? CarbonImmutable::parse($oldest)->toIso8601String() : null,
        ];
    }

    /**
     * @param  list<int>|null  $projectIds  null for the whole table
     */
    private function query(RetentionCategory $category, ?array $projectIds): Builder
    {
        $query = DB::table($category->table());

        return $projectIds === null ? $query : PruneProjectTelemetryAction::scope($query, $category, $projectIds);
    }

    private function estimate(string $table): int
    {
        return max(0, (int) DB::table('pg_class')->where('relname', $table)->value('reltuples'));
    }
}
