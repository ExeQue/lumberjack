<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $host
 * @property string $name
 * @property string|null $sdk
 * @property string|null $sdk_version
 * @property string|null $agent
 * @property string|null $agent_version
 * @property string|null $runtime
 * @property string|null $runtime_version
 * @property string|null $framework
 * @property string|null $framework_version
 * @property CarbonImmutable $last_seen_at
 */
#[Fillable(['project_id', 'host', 'name', 'sdk', 'sdk_version', 'agent', 'agent_version', 'runtime', 'runtime_version', 'framework', 'framework_version', 'last_seen_at'])]
#[UseFactory(ProjectAgentFactory::class)]
class ProjectAgent extends Model
{
    /** @use HasFactory<ProjectAgentFactory> */
    use HasFactory;
    use Prunable;

    /**
     * An agent heartbeats every minute; five missed beats means it is gone.
     */
    public const int OFFLINE_AFTER_MINUTES = 5;

    /**
     * Gone for a day means the server or pod is gone too: Kubernetes and autoscaling
     * name every pod for itself, so old names would pile up as offline forever.
     */
    public const int FORGET_AFTER_HOURS = 24;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::query()->where('last_seen_at', '<', now()->subHours(self::FORGET_AFTER_HOURS));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at->greaterThan(now()->subMinutes(self::OFFLINE_AFTER_MINUTES));
    }
}
