<?php

declare(strict_types=1);

namespace App\Domains\Logs\Models;

use App\Domains\Projects\Models\Project;
use Database\Factories\LogChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 */
#[Fillable(['project_id', 'name'])]
#[UseFactory(LogChannelFactory::class)]
class LogChannel extends Model
{
    /** @use HasFactory<LogChannelFactory> */
    use HasFactory;

    public const string DEFAULT = 'default';

    public const string NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<LogEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LogEntry::class);
    }

    public function isDefault(): bool
    {
        return $this->name === self::DEFAULT;
    }
}
