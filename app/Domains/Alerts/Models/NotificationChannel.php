<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Alerts\Eloquent\Builders\NotificationChannelBuilder;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property ChannelType $type
 * @property string $name
 * @property array<string, string> $config
 * @property string|null $last_delivery_status
 * @property CarbonImmutable|null $last_delivery_at
 */
#[Fillable(['team_id', 'project_id', 'type', 'name', 'config', 'last_delivery_status', 'last_delivery_at'])]
#[Hidden(['config'])]
#[UseFactory(NotificationChannelFactory::class)]
#[UseEloquentBuilder(NotificationChannelBuilder::class)]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;
    /** A secret made while saving, to show once; never stored apart from config. */
    public ?string $revealSecret = null;

    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config' => 'encrypted:array',
            'last_delivery_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
