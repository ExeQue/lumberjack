<?php

declare(strict_types=1);

namespace App\Domains\Teams\Models;

use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property-read Membership $pivot  set when loaded through a user's teams
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
#[UseFactory(TeamFactory::class)]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
        ];
    }

    /** @return BelongsToMany<User, $this, Membership, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<NotificationChannel, $this> */
    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    /** @return HasMany<McpToken, $this> */
    public function mcpTokens(): HasMany
    {
        return $this->hasMany(McpToken::class);
    }

    /** @return HasMany<RetentionPolicy, $this> */
    public function retentionPolicies(): HasMany
    {
        return $this->hasMany(RetentionPolicy::class);
    }

    public function roleOf(User $user): ?TeamRole
    {
        $member = $this->members()->whereKey($user->getKey())->first();

        return $member?->pivot->role;
    }
}
