<?php

declare(strict_types=1);

namespace App\Domains\Performance\Models;

use App\Domains\Performance\Enums\SpanKind;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\SpanRunFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cache read, outgoing call, mail, notification or Redis command on a trace.
 *
 * @property int $id
 * @property int $project_id
 * @property string|null $trace_id
 * @property string|null $environment
 * @property SpanKind $kind
 * @property string $name
 * @property string $target
 * @property int|null $duration_us
 * @property array<string, mixed>|null $attributes
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'trace_id', 'environment', 'kind', 'name', 'target', 'duration_us', 'attributes', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(SpanRunFactory::class)]
class SpanRun extends Model
{
    /** @use HasFactory<SpanRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => SpanKind::class,
            'attributes' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
