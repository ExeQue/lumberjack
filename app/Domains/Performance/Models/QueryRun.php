<?php

declare(strict_types=1);

namespace App\Domains\Performance\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\QueryRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string $connection
 * @property string $sql
 * @property string $sql_hash
 * @property string|null $location
 * @property int $duration_us
 * @property float $sample_rate
 * @property bool $sampled
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'trace_id', 'environment', 'connection', 'sql', 'sql_hash', 'location', 'duration_us', 'sample_rate', 'sampled', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(QueryRunFactory::class)]
class QueryRun extends Model
{
    /** @use HasFactory<QueryRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sample_rate' => 'float',
            'sampled' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
