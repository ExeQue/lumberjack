<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Screens\Eloquent\Builders\DeviceBuilder;
use App\Domains\Screens\Support\DeviceName;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A computer or TV paired to a team ("Office PC"), told apart from others by its device
 * cookie, of which only the sha256 is stored. Each browser window it runs as /tv/{slot}
 * is one of its displays.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $name
 * @property string|null $device_hash sha256 of the browser's device cookie, shared by its windows
 * @property string|null $user_agent
 * @property string|null $ip
 * @property CarbonImmutable|null $last_seen_at when any of its windows was last heard from
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'name', 'device_hash', 'user_agent', 'ip', 'last_seen_at', 'created_by'])]
#[Hidden(['device_hash'])]
#[UseEloquentBuilder(DeviceBuilder::class)]
#[UseFactory(DeviceFactory::class)]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
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
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Display, $this> */
    public function displays(): HasMany
    {
        return $this->hasMany(Display::class);
    }

    /** "Fire TV · Silk", from the browser it last used. */
    public function browser(): ?string
    {
        return DeviceName::from($this->user_agent);
    }

    /** Online while any of its windows is. */
    public function isOnline(): bool
    {
        return $this->displays->contains(fn (Display $d): bool => $d->isOnline());
    }

    /** The lowest window number none of its windows uses yet; null when all are taken. */
    public function nextSlot(): ?int
    {
        return array_values(array_diff(range(1, Display::MAX_SLOT), $this->displays->pluck('slot')->all()))[0] ?? null;
    }
}
