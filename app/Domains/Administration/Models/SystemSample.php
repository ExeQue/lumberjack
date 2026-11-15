<?php

declare(strict_types=1);

namespace App\Domains\Administration\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SystemSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What the server Lumberjack runs on was doing at one minute.
 *
 * @property int $id
 * @property float|null $cpu_load_1m
 * @property float|null $cpu_cores
 * @property int|null $memory_used_bytes
 * @property int|null $memory_total_bytes
 * @property int|null $disk_used_bytes
 * @property int|null $disk_total_bytes
 * @property CarbonImmutable $sampled_at
 */
#[Fillable(['cpu_load_1m', 'cpu_cores', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes', 'sampled_at'])]
#[UseFactory(SystemSampleFactory::class)]
class SystemSample extends Model
{
    /** @use HasFactory<SystemSampleFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'cpu_load_1m' => 'float',
            'cpu_cores' => 'float',
            'memory_used_bytes' => 'integer',
            'memory_total_bytes' => 'integer',
            'disk_used_bytes' => 'integer',
            'disk_total_bytes' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }

    /**
     * The share of the disk in use, from 0 to 1; null when the disk could not be read.
     */
    public function diskUsed(): ?float
    {
        return $this->disk_used_bytes !== null && $this->disk_total_bytes > 0 ? $this->disk_used_bytes / $this->disk_total_bytes : null;
    }
}
