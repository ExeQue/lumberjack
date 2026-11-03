<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Administration\Casts\NotificationPreferencesCast;
use App\Domains\Administration\Data\NotificationPreferences;
use App\Domains\Administration\Eloquent\Builders\UserBuilder;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Membership;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $current_team_id
 * @property-read Membership $pivot  set when loaded through a team's members
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property bool $password_change_required
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property NotificationPreferences $notification_preferences
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UseEloquentBuilder(UserBuilder::class)]
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The uuid names the user outside the database, e.g. in broadcast channels; the
     * id stays the key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'password_change_required' => 'boolean',
            'suspended_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'notification_preferences' => NotificationPreferencesCast::class,
        ];
    }

    /** @return BelongsToMany<Team, $this, Membership, 'pivot'> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsTo<Team, $this> */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Membership checks run on every policy call; the teams relation is loaded
     * once per request and answered from memory after that.
     */
    /**
     * A system administrator: sees and manages every team on this Lumberjack.
     */
    public function isAdmin(): bool
    {
        return $this->is_admin && ! $this->isSuspended();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * A member, or an administrator, who may be in any team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->isAdmin() || $this->teams->contains($team);
    }

    /**
     * The role in the team; an administrator outside it acts as its owner.
     */
    public function roleIn(Team $team): ?TeamRole
    {
        return $this->teams->firstWhere('id', $team->id)?->pivot->role ?? ($this->isAdmin() ? TeamRole::Owner : null);
    }

    public function canAdministerTeam(Team $team): bool
    {
        return $this->roleIn($team)?->canAdminister() ?? false;
    }
}
