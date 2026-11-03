<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HostSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_agent_id
 * @property float|null $cpu_load_1m
 * @property float|null $cpu_cores
 * @property int|null $memory_used_bytes
 * @property int|null $memory_total_bytes
 * @property int|null $disk_used_bytes
 * @property int|null $disk_total_bytes
 * @property int|null $php_memory_peak_bytes
 * @property CarbonImmutable $sampled_at
 */
#[Fillable(['project_agent_id', 'cpu_load_1m', 'cpu_cores', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes', 'php_memory_peak_bytes', 'sampled_at'])]
#[UseFactory(HostSampleFactory::class)]
class HostSample extends Model
{
    /** @use HasFactory<HostSampleFactory> */
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
            'php_memory_peak_bytes' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProjectAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(ProjectAgent::class, 'project_agent_id');
    }
}
