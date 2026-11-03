<?php

declare(strict_types=1);

namespace App\Domains\Performance\Actions;

use App\Domains\Ingest\Data\Envelope;
use App\Domains\Performance\Models\RequestCount;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Adds a batch's request_counts events to the per-minute counts. Under PHP-FPM every
 * request sends its own event with a count of 1, so the batch is summed up first and
 * each minute, method, route and status is one upsert row.
 */
class RecordRequestCountsAction
{
    /** Rows per event the SDK sends at most, plus its one "other" row. */
    public const int MAX_ROWS = 501;

    /**
     * @param  list<array<string, mixed>>  $events
     * @return int rows written
     */
    public function execute(Project $project, array $events, Envelope $envelope): int
    {
        $rows = [];

        foreach ($events as $event) {
            $minute = Envelope::time($event['timestamp'] ?? $event['to'] ?? null)->startOfMinute()->format('Y-m-d H:i:s');

            foreach (array_slice(is_array($event['counts'] ?? null) ? $event['counts'] : [], 0, self::MAX_ROWS) as $count) {
                if (! is_array($count) || ! isset($count['count']) || ! is_numeric($count['count']) || (int) $count['count'] < 1) {
                    continue;
                }

                $row = [
                    'project_id' => $project->id,
                    'environment' => $envelope->environment ?? '',
                    'minute' => $minute,
                    'method' => mb_substr(strtoupper(Envelope::short($count['method'] ?? null, 10) ?? 'GET'), 0, 10),
                    'route' => Envelope::short($count['route'] ?? null, 255) ?? '/',
                    'status_code' => max(0, min(999, (int) ($count['status'] ?? 0))),
                ];
                $key = implode("\0", $row);

                $rows[$key] ??= [...$row, 'count' => 0, 'duration_us' => 0];
                $rows[$key]['count'] += (int) $count['count'];
                $rows[$key]['duration_us'] += is_numeric($count['duration_ms_sum'] ?? null) ? max(0, (int) round((float) $count['duration_ms_sum'] * 1000)) : 0;
            }
        }

        // One order for every writer, so two batches upserting the same rows do not deadlock.
        ksort($rows);

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            RequestCount::query()->upsert($chunk, ['project_id', 'environment', 'minute', 'method', 'route', 'status_code'], [
                'count' => DB::raw('request_counts.count + excluded.count'),
                'duration_us' => DB::raw('request_counts.duration_us + excluded.duration_us'),
            ]);
        }

        return count($rows);
    }
}
