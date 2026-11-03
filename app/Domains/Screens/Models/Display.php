<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Screens\Eloquent\Builders\DisplayBuilder;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Factories\DisplayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A TV paired to a team, showing one of its screens. It proves itself with a display
 * token (a cookie on the TV) of which only the sha256 is stored.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int|null $screen_id
 * @property string $name
 * @property DisplayTheme $theme
 * @property string|null $token_hash
 * @property string|null $user_agent
 * @property string|null $ip
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'screen_id', 'name', 'theme', 'token_hash', 'user_agent', 'ip', 'last_seen_at'])]
#[Hidden(['token_hash'])]
#[UseEloquentBuilder(DisplayBuilder::class)]
#[UseFactory(DisplayFactory::class)]
class Display extends Model
{
    /** @use HasFactory<DisplayFactory> */
    use HasFactory;
    use HasUuids;

    /** Seen this recently, a display counts as online. */
    public const int ONLINE_SECONDS = 180;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Also what the display.{display} broadcast channel binds on.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'theme' => DisplayTheme::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Screen, $this> */
    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
