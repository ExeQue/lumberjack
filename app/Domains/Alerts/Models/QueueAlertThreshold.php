<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's own queue backlog thresholds for one queue: each field set replaces the
 * rule's, a null one keeps it. A muted queue never alerts. See QueueBacklogSettings::forQueue().
 *
 * @property int $id
 * @property int $project_id
 * @property string $connection
 * @property string $queue
 * @property int|null $size
 * @property int|null $oldest_minutes
 * @property int|null $sustained_minutes
 * @property bool $muted
 */
#[Fillable(['project_id', 'connection', 'queue', 'size', 'oldest_minutes', 'sustained_minutes', 'muted'])]
class QueueAlertThreshold extends Model
{
    protected function casts(): array
    {
        return ['size' => 'integer', 'oldest_minutes' => 'integer', 'sustained_minutes' => 'integer', 'muted' => 'boolean'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array{connection: string, queue: string, size: int|null, oldest_minutes: int|null, sustained_minutes: int|null, muted: bool}
     */
    public function toSummary(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'size' => $this->size,
            'oldest_minutes' => $this->oldest_minutes,
            'sustained_minutes' => $this->sustained_minutes,
            'muted' => $this->muted,
        ];
    }
}
