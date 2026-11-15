<?php

declare(strict_types=1);

namespace App\Domains\Administration\Actions;

use App\Domains\Administration\Models\SystemSample;
use App\Domains\Administration\Notifications\SystemDiskNotification;
use App\Domains\Administration\Support\SystemProbe;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Runs every minute: records what the server is doing, and mails the administrators once
 * when its disk is nearly full. A full disk stops Lumberjack taking anything in, and
 * unlike a busy CPU it does not pass by itself.
 */
final class RecordSystemSampleAction
{
    /** The share of the disk in use from which the administrators are told. */
    public const float DISK_ALERT_AT = 0.9;

    /** Told again only after the disk has been back under this, so a disk at the limit does not mail every minute. */
    public const float DISK_CLEAR_UNDER = 0.85;

    public const string DISK_ALERTED_KEY = 'system:disk-alerted';

    public function __construct(
        private readonly SystemProbe $probe,
    ) {
    }

    public function execute(): SystemSample
    {
        $memory = $this->probe->memory();
        $disk = $this->probe->disk();

        $sample = SystemSample::query()->create([
            'cpu_load_1m' => $this->probe->cpuLoad(),
            'cpu_cores' => $this->probe->cpuCores(),
            'memory_used_bytes' => $memory['used'] ?? null,
            'memory_total_bytes' => $memory['total'] ?? null,
            'disk_used_bytes' => $disk['used'] ?? null,
            'disk_total_bytes' => $disk['total'] ?? null,
            'sampled_at' => now(),
        ]);

        $this->watchDisk($sample);

        return $sample;
    }

    private function watchDisk(SystemSample $sample): void
    {
        $used = $sample->diskUsed();

        if ($used === null) {
            return;
        }

        if ($used < self::DISK_CLEAR_UNDER) {
            Cache::forget(self::DISK_ALERTED_KEY);

            return;
        }

        // add() only stores when nothing is there, so two samplers at once tell once.
        if ($used >= self::DISK_ALERT_AT && Cache::add(self::DISK_ALERTED_KEY, true)) {
            Notification::send(User::query()->whereActiveAdmin()->get(), new SystemDiskNotification($sample));
        }
    }
}
