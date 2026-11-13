<?php

declare(strict_types=1);

namespace App\Domains\Activity\Queries;

use App\Domains\Shared\Enums\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The actors or subjects in the period that match what is typed, as "type:id", most
 * active first and at most LIMIT of each type, for the filter fields' suggestions. "user:1" matches users whose key
 * starts with 1; text without a colon matches the start of a type or of a key.
 */
class ActivityReferencesQuery
{
    /** Suggestions per type. */
    public const int LIMIT = 20;

    /** Types suggested at most. */
    public const int MAX_TYPES = 20;

    /**
     * @param  'actor'|'subject'  $kind
     * @param  array{0: string, 1: string}|null  $event  only this group and name
     * @return list<string>
     */
    public function search(int $projectId, Period $period, string $kind, string $term, ?string $environment = null, ?array $event = null): array
    {
        [$type, $id] = $kind === 'actor'
            ? ['actor_type', 'actor_id']
            : ["(subject->>'type')", "(subject->>'id')"];

        $query = DB::table('activities')
            ->where('project_id', $projectId)
            ->where('occurred_at', '>=', $period->since()->format('Y-m-d H:i:s'))
            ->when($environment !== null)->where('environment', $environment)
            ->when($event !== null)->where('group', $event[0] ?? '')
            ->when($event !== null)->where('name', $event[1] ?? '')
            ->when($kind === 'actor')->whereNotNull('actor_type')
            ->when($kind === 'subject')->whereNotNull('subjects')
            ->when($kind === 'subject')->crossJoin(DB::raw('jsonb_array_elements(activities.subjects) AS subjects_list(subject)'));

        $this->match($query, $type, $id, trim($term));

        $ranked = $query
            ->selectRaw("{$type} AS type, {$type} || ':' || {$id} AS reference, COUNT(*) AS c, ROW_NUMBER() OVER (PARTITION BY {$type} ORDER BY COUNT(*) DESC) AS rank")
            ->groupByRaw("{$type}, {$id}");

        return array_values(DB::query()->fromSub($ranked, 'r')
            ->where('rank', '<=', self::LIMIT)
            ->orderByDesc('c')
            ->orderBy('reference')
            ->limit(self::LIMIT * self::MAX_TYPES)
            ->pluck('reference')
            ->map(fn (mixed $reference): string => (string) $reference)
            ->all());
    }

    /**
     * @param  literal-string  $type
     * @param  literal-string  $id
     */
    private function match(Builder $query, string $type, string $id, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = fn (string $value): string => addcslashes($value, '\\%_').'%';
        $colon = strpos($term, ':');

        if ($colon === false) {
            $query->where(fn (Builder $q) => $q->whereRaw("{$type} ILIKE ?", [$like($term)])->orWhereRaw("{$id} LIKE ?", [$like($term)]));

            return;
        }

        $query->whereRaw("{$type} = ?", [substr($term, 0, $colon)])->whereRaw("{$id} LIKE ?", [$like(substr($term, $colon + 1))]);
    }
}
