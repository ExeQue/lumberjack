<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Eloquent\Builders\AiUsageHourlyBuilder;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\AiUsageHourlyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An hour of AI calls per environment, provider, model, key and key owner. Kept longer than the calls.
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment empty when the batch had none
 * @property string $provider
 * @property string $model
 * @property string $fingerprint empty for calls without a key
 * @property string $owner_type the call's key owner; empty without one
 * @property string $owner_id
 * @property CarbonImmutable $hour
 * @property int $calls
 * @property int $errors
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property float $duration_ms_sum
 */
#[Table('ai_usage_hourly')]
#[Fillable(['project_id', 'environment', 'provider', 'model', 'fingerprint', 'owner_type', 'owner_id', 'hour', 'calls', 'errors', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'duration_ms_sum'])]
#[WithoutTimestamps]
#[UseEloquentBuilder(AiUsageHourlyBuilder::class)]
#[UseFactory(AiUsageHourlyFactory::class)]
class AiUsageHourly extends Model
{
    /** @use HasFactory<AiUsageHourlyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hour' => 'datetime',
            'calls' => 'integer',
            'errors' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'duration_ms_sum' => 'float',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
