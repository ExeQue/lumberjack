<?php

declare(strict_types=1);

namespace App\Domains\Administration\Queries;

use App\Domains\Administration\Models\SystemSample;
use App\Domains\Administration\Support\SystemProbe;
use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Shared\Enums\Period;
use Illuminate\Support\Facades\DB;

/**
 * How the server Lumberjack runs on is doing, for its administrators: the samples over a
 * period, and what is read as the page loads (the database, the queues, Redis).
 */
class SystemStatusQuery
{
    /** The largest tables shown; the rest are small. */
    private const int TABLES = 12;

    public function __construct(
        private readonly SystemProbe $probe,
    ) {
    }

    /**
     * @return array{sampled_at: string, cpu_load_1m: float|null, cpu_cores: float|null, memory_used_bytes: int|null, memory_total_bytes: int|null, disk_used_bytes: int|null, disk_total_bytes: int|null}|null
     */
    public function latest(): ?array
    {
        $sample = SystemSample::query()->latest('sampled_at')->first();

        return $sample === null ? null : [
            'sampled_at' => $sample->sampled_at->toIso8601String(),
            'cpu_load_1m' => $sample->cpu_load_1m,
            'cpu_cores' => $sample->cpu_cores,
            'memory_used_bytes' => $sample->memory_used_bytes,
            'memory_total_bytes' => $sample->memory_total_bytes,
            'disk_used_bytes' => $sample->disk_used_bytes,
            'disk_total_bytes' => $sample->disk_total_bytes,
        ];
    }

    /**
     * The load per core and the shares of memory and disk in use, from 0 to 1, on the
     * same buckets; null where nothing was sampled. The CPU is the bucket's average, memory
     * and disk its highest: a short peak in load is no trouble, one in memory is.
     *
     * @return array{cpu: list<array{t: string, v: float|null}>, memory: list<array{t: string, v: float|null}>, disk: list<array{t: string, v: float|null}>}
     */
    public function charts(Period $period): array
    {
        $size = $period->bucketSeconds();
        $start = intdiv($period->since()->getTimestamp(), $size) * $size;

        $rows = DB::table('system_samples')
            ->where('sampled_at', '>=', date('Y-m-d H:i:s', $start))
            ->selectRaw('FLOOR(EXTRACT(EPOCH FROM sampled_at) / ?) * ? AS bucket', [$size, $size])
            ->selectRaw('AVG(cpu_load_1m / NULLIF(cpu_cores, 0)) AS cpu')
            ->selectRaw('MAX(memory_used_bytes::float / NULLIF(memory_total_bytes, 0)) AS memory')
            ->selectRaw('MAX(disk_used_bytes::float / NULLIF(disk_total_bytes, 0)) AS disk')
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->bucket);

        $charts = ['cpu' => [], 'memory' => [], 'disk' => []];

        for ($bucket = $start; $bucket <= now()->getTimestamp(); $bucket += $size) {
            $row = $rows->get($bucket);

            foreach (array_keys($charts) as $figure) {
                $value = $row?->{$figure};
                $charts[$figure][] = ['t' => gmdate('Y-m-d\TH:i:s\Z', $bucket), 'v' => $value === null ? null : round((float) $value, 4)];
            }
        }

        return $charts;
    }

    /**
     * The database's size and its largest tables, each under the name of the data it
     * keeps where the retention page has one.
     *
     * @return array{bytes: int, tables: list<array{table: string, label: string|null, bytes: int, rows: int}>}
     */
    public function database(): array
    {
        $labels = [];

        foreach (RetentionCategory::cases() as $category) {
            $labels[$category->table()] = $category->label();
        }

        $tables = DB::table('pg_class')
            ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_class.relnamespace')
            ->where('pg_class.relkind', 'r')
            ->whereRaw('pg_namespace.nspname = current_schema()')
            ->selectRaw('pg_class.relname AS name, pg_total_relation_size(pg_class.oid) AS bytes, GREATEST(pg_class.reltuples, 0) AS rows')
            ->orderByDesc('bytes')
            ->limit(self::TABLES)
            ->get();

        return [
            'bytes' => (int) DB::scalar('SELECT pg_database_size(current_database())'),
            'tables' => array_values(array_map(fn (object $table): array => [
                'table' => (string) $table->name,
                'label' => $labels[$table->name] ?? null,
                'bytes' => (int) $table->bytes,
                'rows' => (int) $table->rows,
            ], $tables->all())),
        ];
    }

    /**
     * @return list<array{name: string, length: int, wait_seconds: int, processes: int}>|null
     */
    public function queues(): ?array
    {
        return $this->probe->queues();
    }

    /**
     * @return array{used_bytes: int, max_bytes: int|null}|null
     */
    public function redis(): ?array
    {
        return $this->probe->redisMemory();
    }
}
