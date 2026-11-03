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
 * One window of a paired device (/tv/{slot}), showing one of its team's screens or several
 * in turn. It proves itself with a display token (a cookie in that window) of which only
 * the sha256 is stored. The team is the device's, kept here too for channel and screen
 * checks.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int $device_id
 * @property int|null $screen_id
 * @property string|null $name its own name, if it has one besides its window number
 * @property DisplayTheme $theme
 * @property string|null $token_hash
 * @property int|null $viewport_width the TV window's size in CSS pixels, as it last said
 * @property int|null $viewport_height
 * @property float|null $pixel_ratio
 * @property CarbonImmutable|null $last_seen_at
 * @property int $slot which window of its computer, /tv/{slot}
 * @property list<int>|null $playlist screen ids shown in turn; empty shows screen_id alone
 * @property int $rotate_seconds
 * @property bool $hold_on_alert whether a screen with a red widget stays until it clears
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'device_id', 'screen_id', 'name', 'theme', 'token_hash', 'viewport_width', 'viewport_height', 'pixel_ratio', 'last_seen_at', 'slot', 'playlist', 'rotate_seconds', 'hold_on_alert'])]
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
            'viewport_width' => 'integer',
            'viewport_height' => 'integer',
            'pixel_ratio' => 'float',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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

    /** "Window 2", or its own name when it has one. */
    public function label(): string
    {
        return $this->name ?? __('screens.displays.window', ['slot' => $this->slot]);
    }

    /** "Office PC, window 2": which window of which device. */
    public function fullName(): string
    {
        return $this->name === null
            ? __('screens.displays.full_name', ['device' => $this->device->name, 'slot' => $this->slot])
            : __('screens.displays.full_name_named', ['device' => $this->device->name, 'name' => $this->name]);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
