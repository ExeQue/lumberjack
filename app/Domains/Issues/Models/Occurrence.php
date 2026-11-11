<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $issue_id
 * @property int $project_id
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $host
 * @property string|null $user_id
 * @property list<array<string, mixed>>|null $stacktrace
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $occurred_at
 * @property bool $under_maintenance came in during a maintenance window of its environment
 */
#[Fillable(['issue_id', 'project_id', 'trace_id', 'environment', 'release', 'host', 'user_id', 'stacktrace', 'context', 'occurred_at', 'under_maintenance'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(OccurrenceFactory::class)]
class Occurrence extends Model
{
    /** @use HasFactory<OccurrenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'stacktrace' => 'array',
            'context' => 'array',
            'occurred_at' => 'datetime',
            'under_maintenance' => 'boolean',
        ];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
