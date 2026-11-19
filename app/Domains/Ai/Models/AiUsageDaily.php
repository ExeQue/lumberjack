<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Eloquent\Builders\AiUsageDailyBuilder;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\AiUsageDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day of AI calls per environment, provider, model, key, key owner and actor: what each
 * key and each actor cost. Kept longer than the calls.
 *
 * @property int $id
 * @property int $project_id
 * @property string $environment empty when the batch had none
 * @property string $provider
 * @property string $model
 * @property string $fingerprint empty for calls without a key
 * @property string $owner_type empty when the key has no owner
 * @property string $owner_id
 * @property string $actor_type empty when no actor caused the calls
 * @property string $actor_id
 * @property CarbonImmutable $day
 * @property int $calls
 * @property int $errors
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $reasoning_tokens
 * @property float $duration_ms_sum
 */
#[Table('ai_usage_daily')]
#[Fillable(['project_id', 'environment', 'provider', 'model', 'fingerprint', 'owner_type', 'owner_id', 'actor_type', 'actor_id', 'day', 'calls', 'errors', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'duration_ms_sum'])]
#[WithoutTimestamps]
#[UseEloquentBuilder(AiUsageDailyBuilder::class)]
#[UseFactory(AiUsageDailyFactory::class)]
class AiUsageDaily extends Model
{
    /** @use HasFactory<AiUsageDailyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day' => 'date',
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
