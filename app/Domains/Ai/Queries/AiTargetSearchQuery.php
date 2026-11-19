<?php

declare(strict_types=1);

namespace App\Domains\Ai\Queries;

use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Presenters\AiUsagePresenter;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the budget and price forms offer as keys, key owners or actors for what is typed:
 * a page per provider or type, the most lately used first, so one busy type never crowds
 * the others out.
 */
class AiTargetSearchQuery
{
    /** Matches given back per provider or type, at most. */
    public const int PER_GROUP = 20;

    // ponytail: 20 per group x 25 groups (the groups used most lately) caps an answer at 500 rows; page the groups if projects keep more types than that.
    /** Providers or types given back, at most. */
    public const int GROUPS = 25;

    /** How far back the daily totals name an owner or actor that is offered. */
    public const int DAYS = 90;

    /**
     * @return list<array<string, mixed>>
     */
    public function keys(Project $project, string $term): array
    {
        $ids = $this->top(AiKey::query()->whereProject($project)
            ->when($term !== '')->whereSearch($term)
            ->toBase()->select(['id', 'last_seen_at as used_at'])->selectRaw("COALESCE(provider, '') AS grp")->selectRaw('id::text AS item'))
            ->pluck('item')->all();
        $keys = AiKey::query()->whereIn('id', $ids)->get()->keyBy(fn (AiKey $key): string => (string) $key->id);

        // A key pruned between the two reads is left out.
        return array_values(array_map(
            fn (string $id): array => AiUsagePresenter::key($keys[$id]),
            array_filter(array_map('strval', $ids), fn (string $id): bool => $keys->has($id)),
        ));
    }

    /**
     * Owners named by calls of the last days or owning a key, by the day they were last used
     * (the daily totals know no finer), the same owner from both once.
     *
     * @return list<array{type: string, id: string}>
     */
    public function owners(Project $project, string $term): array
    {
        // ponytail: scans 90 days of daily totals per keystroke (the project's index narrows it); keep a distinct owners and actors table if projects name too many for that.
        $named = AiUsageDaily::query()->whereProject($project)->whereNamesOwner()->whereDaySince(now()->subDays(self::DAYS))
            ->when($term !== '')->whereOwnerLike($term)
            ->toBase()->groupBy('owner_type', 'owner_id')->select(['owner_type as type', 'owner_id as id'])->selectRaw('MAX(day)::timestamp AS used_at');
        $owning = AiKey::query()->whereProject($project)->whereOwned()
            ->when($term !== '')->whereOwnerLike($term)
            ->toBase()->groupBy('owner_type', 'owner_id')->select(['owner_type as type', 'owner_id as id'])->selectRaw('MAX(last_seen_at)::date::timestamp AS used_at');

        return $this->pairs($named->unionAll($owning));
    }

    /**
     * @return list<array{type: string, id: string}>
     */
    public function actors(Project $project, string $term): array
    {
        return $this->pairs(AiUsageDaily::query()->whereProject($project)->whereNamesActor()->whereDaySince(now()->subDays(self::DAYS))
            ->when($term !== '')->whereActorLike($term)
            ->toBase()->groupBy('actor_type', 'actor_id')->select(['actor_type as type', 'actor_id as id'])->selectRaw('MAX(day)::timestamp AS used_at'));
    }

    /**
     * Pairs, each once at its latest use.
     *
     * @return list<array{type: string, id: string}>
     */
    private function pairs(Builder $pairs): array
    {
        $latest = DB::query()->fromSub($pairs, 'p')->groupBy('type', 'id')
            ->select(['type as grp'])->selectRaw('MAX(used_at) AS used_at')->selectRaw('json_build_array(type, id)::text AS item');

        return array_values($this->top($latest)->map(function (stdClass $row): array {
            /** @var array{0: string, 1: string} $pair */
            $pair = json_decode((string) $row->item, true, flags: JSON_THROW_ON_ERROR);

            return ['type' => (string) $pair[0], 'id' => (string) $pair[1]];
        })->all());
    }

    /**
     * The most lately used of rows (grp, used_at, item): PER_GROUP per group, of the GROUPS
     * groups used most lately; groups by their latest use, then by name, rows by use, then item.
     *
     * @return Collection<int, stdClass>
     */
    private function top(Builder $rows): Collection
    {
        $ranked = DB::query()->fromSub($rows, 'r')->select(['grp', 'used_at', 'item'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY grp ORDER BY used_at DESC, item) AS rank_in_group')
            ->selectRaw('MAX(used_at) OVER (PARTITION BY grp) AS group_used_at');
        $grouped = DB::query()->fromSub($ranked, 'k')->where('rank_in_group', '<=', self::PER_GROUP)->select(['grp', 'used_at', 'item', 'rank_in_group', 'group_used_at'])
            ->selectRaw('DENSE_RANK() OVER (ORDER BY group_used_at DESC, grp) AS group_rank');

        return DB::query()->fromSub($grouped, 'g')->where('group_rank', '<=', self::GROUPS)
            ->orderBy('group_rank')->orderBy('rank_in_group')->get(['item']);
    }
}
