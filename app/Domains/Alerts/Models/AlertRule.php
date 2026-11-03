<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Alerts\Data\QueueBacklogSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Projects\Models\Project;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $project_id
 * @property AlertRuleType $type
 * @property bool $enabled
 * @property list<string>|null $environments
 * @property array<string, mixed>|null $settings
 */
#[Fillable(['project_id', 'type', 'enabled', 'environments', 'settings'])]
#[UseFactory(AlertRuleFactory::class)]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    /**
     * At most one message per issue per rule within this window.
     */
    public const int THROTTLE_MINUTES = 30;

    protected function casts(): array
    {
        return [
            'type' => AlertRuleType::class,
            'enabled' => 'boolean',
            'environments' => 'array',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<NotificationChannel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    public function queueBacklogSettings(): QueueBacklogSettings
    {
        return QueueBacklogSettings::fromArray($this->settings);
    }

    public function appliesToEnvironment(?string $environment): bool
    {
        return $this->environments === null || $this->environments === [] || in_array($environment, $this->environments, true);
    }
}
