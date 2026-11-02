<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $host
 * @property string $name
 * @property string|null $version
 * @property CarbonImmutable $last_seen_at
 */
#[Fillable(['project_id', 'host', 'name', 'version', 'last_seen_at'])]
#[UseFactory(ProjectAgentFactory::class)]
class ProjectAgent extends Model
{
    /** @use HasFactory<ProjectAgentFactory> */
    use HasFactory;

    /**
     * An agent heartbeats every minute; five missed beats means it is gone.
     */
    public const int OFFLINE_AFTER_MINUTES = 5;

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

    public function isOnline(): bool
    {
        return $this->last_seen_at->greaterThan(now()->subMinutes(self::OFFLINE_AFTER_MINUTES));
    }
}
