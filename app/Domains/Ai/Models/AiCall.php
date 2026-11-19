<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Eloquent\Builders\AiCallBuilder;
use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\AiCallFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call an app made to a model: its tokens, which key it was made with (by fingerprint),
 * whose key that is and who caused the call. Never the prompt or the answer.
 *
 * @property int $id
 * @property int $project_id
 * @property string|null $fingerprint
 * @property string $provider
 * @property string|null $driver
 * @property string $model
 * @property AiOperation $operation
 * @property int|null $input_tokens all input, cached tokens included
 * @property int|null $output_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property int|null $reasoning_tokens
 * @property float|null $duration_ms
 * @property AiCallStatus $status
 * @property string|null $error
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $invocation_id
 * @property int|null $step
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $host
 * @property AiCallSource $source
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'fingerprint', 'provider', 'driver', 'model', 'operation', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'duration_ms', 'status', 'error', 'actor_type', 'actor_id', 'owner_type', 'owner_id', 'invocation_id', 'step', 'trace_id', 'environment', 'release', 'host', 'source', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseEloquentBuilder(AiCallBuilder::class)]
#[UseFactory(AiCallFactory::class)]
class AiCall extends Model
{
    /** @use HasFactory<AiCallFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'operation' => AiOperation::class,
            'status' => AiCallStatus::class,
            'source' => AiCallSource::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'duration_ms' => 'float',
            'step' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
