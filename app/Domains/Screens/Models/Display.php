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
 * @property int $slot which window of its computer, /tv/{slot}
 * @property string|null $device_hash sha256 of the computer's device cookie, shared by its windows
 * @property list<int>|null $playlist screen ids shown in turn; empty shows screen_id alone
 * @property int $rotate_seconds
 * @property bool $hold_on_alert whether a screen with a red widget stays until it clears
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'screen_id', 'name', 'theme', 'token_hash', 'user_agent', 'ip', 'last_seen_at', 'slot', 'device_hash', 'playlist', 'rotate_seconds', 'hold_on_alert'])]
#[Hidden(['token_hash', 'device_hash'])]
#[UseEloquentBuilder(DisplayBuilder::class)]
#[UseFactory(DisplayFactory::class)]
class Display extends Model
{
    /** @use HasFactory<DisplayFactory> */
    use HasFactory;
    use HasUuids;

    /** Seen this recently, a display counts as online. */
    public const int ONLINE_SECONDS = 180;

    /** Windows per computer: /tv/1 to /tv/9. */
    public const int MAX_SLOT = 9;

    public const int DEFAULT_ROTATE_SECONDS = 30;

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
            'slot' => 'integer',
            'playlist' => 'array',
            'rotate_seconds' => 'integer',
            'hold_on_alert' => 'boolean',
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

    /**
     * The ids of the screens it shows, in order: its playlist, else its one screen.
     *
     * @return list<int>
     */
    public function screenIds(): array
    {
        $playlist = array_map(intval(...), $this->playlist ?? []);

        if ($playlist !== []) {
            return $playlist;
        }

        return $this->screen_id === null ? [] : [$this->screen_id];
    }

    /**
     * The screens it shows, in order, its own team's only; deleted ones drop out.
     *
     * @return list<Screen>
     */
    public function screens(): array
    {
        $ids = $this->screenIds();
        $screens = Screen::query()->whereTeam($this->team)->whereIn('id', $ids)->with('team')->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?Screen => $screens->get($id), $ids)));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
