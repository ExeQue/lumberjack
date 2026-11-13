<?php

declare(strict_types=1);

namespace App\Domains\Activity\Queries;

use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Per activity in the period: how often it happened, when last, and a count per bucket for
 * its sparkline, for one actor or subject if given. Read from the activity itself, whose
 * retention covers every period.
 */
class ActivityStatisticsQuery
{
    /**
     * @param  array{0: string, 1: string}|null  $event  only this group and name
     * @param  list<array{type: string, id: string}>  $actors  any of them
     * @param  list<array{type: string, id: string}>  $subjects  any of them
     * @return list<array{group: string, name: string, count: int, last_at: string|null, series: list<int>}>
     */
    public function get(int $projectId, Period $period, ?string $environment = null, ?array $event = null, array $actors = [], array $subjects = [], int $limit = 200): array
    {
        $size = $period->bucketSeconds();
        $start = intdiv($period->since()->getTimestamp(), $size);
        $base = fn () => DB::table('activities')
            ->where('project_id', $projectId)
            ->where('occurred_at', '>=', $period->since()->format('Y-m-d H:i:s'))
            ->when($environment !== null)->where('environment', $environment)
            ->when($event !== null)->where('group', $event[0] ?? '')
            ->when($event !== null)->where('name', $event[1] ?? '')
            ->when($actors !== [])->where(function (Builder $q) use ($actors): void {
                foreach ($actors as $actor) {
                    $q->orWhere(fn (Builder $one) => $one->where('actor_type', $actor['type'])->where('actor_id', $actor['id']));
                }
            })
            ->when($subjects !== [])->where(function (Builder $q) use ($subjects): void {
                foreach ($subjects as $subject) {
                    $q->orWhereJsonContains('subjects', [$subject]);
                }
            });

        $rows = $base()
            ->selectRaw('"group", name, COUNT(*) AS c, MAX(occurred_at) AS last_at')
            ->groupBy('group', 'name')
            ->orderByDesc('c')
            ->limit($limit)
            ->get();

        $buckets = $base()
            ->selectRaw('"group", name, FLOOR(EXTRACT(EPOCH FROM occurred_at) / ?) AS b, COUNT(*) AS c', [$size])
            ->groupByRaw('"group", name, b')
            ->get()
            ->groupBy(fn (object $row): string => $row->group."\0".$row->name);

        return array_values($rows->map(function (object $row) use ($buckets, $start, $period): array {
            $series = array_fill(0, $period->buckets() + 1, 0);

            foreach ($buckets->get($row->group."\0".$row->name) ?? [] as $bucket) {
                $index = (int) $bucket->b - $start;

                if ($index >= 0 && $index < count($series)) {
                    $series[$index] += (int) $bucket->c;
                }
            }

            return [
                'group' => (string) $row->group,
                'name' => (string) $row->name,
                'count' => (int) $row->c,
                'last_at' => $row->last_at !== null ? CarbonImmutable::parse((string) $row->last_at)->toIso8601String() : null,
                'series' => $series,
            ];
        })->all());
    }
}
