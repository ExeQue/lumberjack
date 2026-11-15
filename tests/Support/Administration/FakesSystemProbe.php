<?php

declare(strict_types=1);

namespace Tests\Support\Administration;

use App\Domains\Administration\Support\SystemProbe;

trait FakesSystemProbe
{
    /**
     * A server of 4 cores, 8 GB of memory and a 100 GB disk, busy as these shares (0 to 1)
     * of them; a null disk is one that cannot be read. Redis and the queues answer unless
     * told to be down.
     */
    public function fakeSystem(float $cpu = 0.25, float $memory = 0.5, ?float $disk = 0.4, bool $redisDown = false): void
    {
        $this->app->instance(SystemProbe::class, new class ($cpu, $memory, $disk, $redisDown) extends SystemProbe {
            public function __construct(private readonly float $cpu, private readonly float $memoryShare, private readonly ?float $diskShare, private readonly bool $redisDown)
            {
            }

            public function cpuLoad(): ?float
            {
                return $this->cpu * 4;
            }

            public function cpuCores(): ?int
            {
                return 4;
            }

            public function memory(): ?array
            {
                return ['used' => (int) ($this->memoryShare * 8 * 1024 ** 3), 'total' => 8 * 1024 ** 3];
            }

            public function disk(): ?array
            {
                return $this->diskShare === null ? null : ['used' => (int) ($this->diskShare * 100 * 1024 ** 3), 'total' => 100 * 1024 ** 3];
            }

            public function redisMemory(): ?array
            {
                return $this->redisDown ? null : ['used_bytes' => 12 * 1024 ** 2, 'max_bytes' => null];
            }

            public function queues(): ?array
            {
                return $this->redisDown ? null : [['name' => 'ingest', 'length' => 3, 'wait_seconds' => 2, 'processes' => 4]];
            }
        });
    }
}
