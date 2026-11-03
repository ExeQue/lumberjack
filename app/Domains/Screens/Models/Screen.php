<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Screens\Eloquent\Builders\ScreenBuilder;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ScreenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A status screen of a team: a grid of cols x rows cells that a TV shows exactly, filled
 * in order by the widgets (see WidgetSchema for their shape).
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $name
 * @property int $cols
 * @property int $rows
 * @property ScreenTheme $theme
 * @property string $light_from HH:MM
 * @property string $light_to HH:MM
 * @property string $timezone
 * @property array{projects?: list<string>, environment?: string|null, period?: string} $scope
 * @property list<array<string, mixed>> $widgets
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'name', 'cols', 'rows', 'theme', 'light_from', 'light_to', 'timezone', 'scope', 'widgets'])]
#[UseEloquentBuilder(ScreenBuilder::class)]
#[UseFactory(ScreenFactory::class)]
class Screen extends Model
{
    /** @use HasFactory<ScreenFactory> */
    use HasFactory;
    use HasUuids;

    public const int MIN_COLS = 2;

    public const int MAX_COLS = 12;

    public const int MIN_ROWS = 2;

    public const int MAX_ROWS = 6;

    /** @var array<string, mixed> */
    protected $attributes = [
        'scope' => '{"projects":[],"environment":null,"period":"24h"}',
        'widgets' => '[]',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Also what the screen.{screen} broadcast channel binds on.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'theme' => ScreenTheme::class,
            'cols' => 'integer',
            'rows' => 'integer',
            'scope' => 'array',
            'widgets' => 'array',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Dark or light right now: a schedule is light from light_from until light_to in the
     * screen's time zone (across midnight when light_to comes first).
     */
    public function currentTheme(?CarbonInterface $datetime = null): ScreenTheme
    {
        if ($this->theme !== ScreenTheme::Schedule) {
            return $this->theme;
        }

        $local = ($datetime ?? now())->setTimezone($this->timezone);
        $minute = $local->hour * 60 + $local->minute;
        $from = self::minutes($this->light_from);
        $to = self::minutes($this->light_to);
        $light = $from <= $to ? $minute >= $from && $minute < $to : $minute >= $from || $minute < $to;

        return $light ? ScreenTheme::Light : ScreenTheme::Dark;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time.':0'));

        return $hours * 60 + $minutes;
    }
}
