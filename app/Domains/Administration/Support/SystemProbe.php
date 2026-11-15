<?php

declare(strict_types=1);

namespace App\Domains\Administration\Support;

use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * Reads the server Lumberjack runs on, behind a class so tests can answer for it. Every
 * reading is null where it cannot be had (another operating system, Redis down): the
 * page that shows them is the one opened when something is wrong.
 *
 * ponytail: the figures are the machine's, also inside a container; a CPU or memory
 * limit on the container itself is not read. Read the cgroup files if that is asked for.
 */
class SystemProbe
{
    /**
     * The load average over the last minute.
     */
    public function cpuLoad(): ?float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return $load === false ? null : (float) $load[0];
    }

    public function cpuCores(): ?int
    {
        $cores = preg_match_all('/^processor\s*:/m', $this->read('/proc/cpuinfo') ?? '');

        return $cores > 0 ? $cores : null;
    }

    /**
     * @return array{used: int, total: int}|null
     */
    public function memory(): ?array
    {
        return self::memoryFrom($this->read('/proc/meminfo') ?? '');
    }

    /**
     * Memory in use is what is not available to a new program: the page cache the kernel
     * gives back on demand does not count.
     *
     * @return array{used: int, total: int}|null
     */
    public static function memoryFrom(string $meminfo): ?array
    {
        if (preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $total) !== 1 || preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $available) !== 1) {
            return null;
        }

        return ['used' => max(0, (int) $total[1] - (int) $available[1]) * 1024, 'total' => (int) $total[1] * 1024];
    }

    /**
     * The disk Lumberjack's own files are on.
     *
     * @return array{used: int, total: int}|null
     */
    public function disk(): ?array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        return $total === false || $free === false || $total <= 0 ? null : ['used' => (int) ($total - $free), 'total' => (int) $total];
    }

    /**
     * @return array{used_bytes: int, max_bytes: int|null}|null
     */
    public function redisMemory(): ?array
    {
        try {
            $info = Redis::connection()->command('info', ['memory']);
        } catch (Throwable) {
            return null;
        }

        // phpredis answers flat, Predis under the section's name.
        $info = is_array($info) ? ($info['Memory'] ?? $info) : [];

        if (! is_array($info) || ! is_numeric($info['used_memory'] ?? null)) {
            return null;
        }

        $max = (int) ($info['maxmemory'] ?? 0);

        return ['used_bytes' => (int) $info['used_memory'], 'max_bytes' => $max > 0 ? $max : null];
    }

    /**
     * Lumberjack's own queues, as Horizon sees them.
     *
     * @return list<array{name: string, length: int, wait_seconds: int, processes: int}>|null
     */
    public function queues(): ?array
    {
        try {
            $workload = app(WorkloadRepository::class)->get();
        } catch (Throwable) {
            return null;
        }

        return array_map(fn (array $queue): array => [
            'name' => (string) $queue['name'],
            'length' => (int) $queue['length'],
            'wait_seconds' => (int) $queue['wait'],
            'processes' => (int) $queue['processes'],
        ], array_values($workload));
    }

    protected function read(string $path): ?string
    {
        $contents = is_readable($path) ? @file_get_contents($path) : false;

        return $contents === false ? null : $contents;
    }
}
