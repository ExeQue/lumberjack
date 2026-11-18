<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Screens\Eloquent\Builders\ScreenBuilder;
use App\Domains\Screens\Enums\ScreenAspect;
use App\Domains\Screens\Enums\ScreenFit;
use App\Domains\Screens\Enums\ScreenOrientation;
use App\Domains\Screens\Enums\ScreenSpacing;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ScreenFactory;
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
 * A status screen of a team: a grid of cols x rows cells that a TV shows exactly, each
 * widget on the cells its x, y, w and h cover (see WidgetSchema and ScreenLayout).
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $name
 * @property int $cols
 * @property int $rows
 * @property ScreenAspect $aspect
 * @property ScreenOrientation $orientation
 * @property ScreenFit $fit
 * @property bool $show_header whether the TV shows the name, scope, live state and clock above the grid
 * @property bool $show_clock whether that header shows the clock
 * @property string $clock_format '24' or '12' hours
 * @property array<string, mixed>|null $header what the header shows; see header()
 * @property ScreenSpacing $margin the room around the grid
 * @property ScreenSpacing $gap the room between widgets
 * @property ScreenTheme $theme
 * @property string $light_from HH:MM
 * @property string $light_to HH:MM
 * @property string|null $timezone null: the viewer's own (the browser's) time zone
 * @property array{projects?: list<string>, environment?: string|null, period?: string} $scope
 * @property list<array<string, mixed>> $widgets
 * @property int $revision goes up with every change
 * @property string|null $share_key encrypted at rest; found by share_key_hash
 * @property string|null $share_key_hash
 * @property bool $share_titles whether the share link shows issue titles and names, not only counts
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'name', 'cols', 'rows', 'aspect', 'orientation', 'fit', 'margin', 'gap', 'show_header', 'show_clock', 'clock_format', 'header', 'theme', 'light_from', 'light_to', 'timezone', 'scope', 'widgets', 'share_key', 'share_key_hash', 'share_titles'])]
#[Hidden(['share_key', 'share_key_hash'])]
#[UseEloquentBuilder(ScreenBuilder::class)]
#[UseFactory(ScreenFactory::class)]
class Screen extends Model
{
    /** @use HasFactory<ScreenFactory> */
    use HasFactory;
    use HasUuids;

    /** Parts of the header a screen can hide; being offline always shows. */
    public const array HEADER_PARTS = ['logo', 'name', 'team', 'projects', 'environment', 'period', 'attention', 'live'];

    /** How the header's clock writes the date: a format the browser words, or custom (date_pattern). */
    public const array DATE_FORMATS = ['short', 'long', 'medium', 'numeric', 'iso', 'custom'];

    /**
     * A custom date's pieces: [text] as it is, a token, other letters (not a token, so
     * refused) or anything else as it is. The tokens as frame.tsx's kioskDate() writes them.
     */
    public const string DATE_TOKENS = '/\[[^\]]*\]|YYYY|YY|MMMM|MMM|MM|M|dddd|ddd|DD|D|[A-Za-z]+|[^A-Za-z\[]+|\[/';

    /**
     * What is wrong with a custom date pattern, as frame.tsx's datePatternProblem(): the
     * first piece outside [brackets] that is no token, '' when it has no token, else null.
     */
    public static function datePatternProblem(string $pattern): ?string
    {
        preg_match_all(self::DATE_TOKENS, $pattern, $matches);
        $pieces = collect($matches[0]);
        $token = fn (string $piece): bool => preg_match('/^(YYYY|YY|MMMM|MMM|MM|M|dddd|ddd|DD|D)$/', $piece) === 1;

        return $pieces->first(fn (string $piece): bool => ! $token($piece) && preg_match('/^\[.*\]$/', $piece) !== 1 && preg_match('/[A-Za-z\[]/', $piece) === 1)
            ?? ($pieces->contains($token) ? null : '');
    }

    /** How large the header is drawn, medium as before. */
    public const array HEADER_SIZES = ['s', 'm', 'l', 'xl'];

    /** The header's language: browser, or a language tag such as da or en-GB; null is the app's. */
    public const string LOCALE_PATTERN = '/^(browser|[a-z]{2,3}(-[A-Z]{2})?)$/';

    /** What a version of a screen holds; not its identity or share link. */
    public const array DESIGN = ['name', 'cols', 'rows', 'aspect', 'orientation', 'fit', 'margin', 'gap', 'show_header', 'show_clock', 'clock_format', 'header', 'theme', 'light_from', 'light_to', 'timezone', 'scope', 'widgets'];

    public const int MIN_COLS = 2;

    public const int MAX_COLS = 12;

    public const int MIN_ROWS = 2;

    public const int MAX_ROWS = 12;

    /** @var array<string, mixed> */
    protected $attributes = [
        'scope' => '{"projects":[],"environment":null,"period":"24h"}',
        'widgets' => '[]',
        'revision' => 0,
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

    /**
     * What the header shows, whole: the parts hidden, a title instead of the name, whether
     * the clock has the date and seconds, how it writes the date and in which language,
     * and how large it is. Stored or given, with anything else
     * dropped; nothing stored is everything, as before it could be chosen.
     *
     * @param  array<mixed>|null  $given
     * @return array{hide: list<string>, title: string|null, date: bool, seconds: bool, date_format: string, date_pattern: string|null, locale: string|null, size: string}
     */
    public static function header(?array $given): array
    {
        $title = trim((string) ($given['title'] ?? ''));
        $pattern = trim((string) ($given['date_pattern'] ?? ''));
        $locale = (string) ($given['locale'] ?? '');

        return [
            'hide' => array_values(array_unique(array_intersect(array_filter((array) ($given['hide'] ?? []), is_string(...)), self::HEADER_PARTS))),
            'title' => $title !== '' ? $title : null,
            'date' => (bool) ($given['date'] ?? false),
            'seconds' => (bool) ($given['seconds'] ?? false),
            'date_format' => in_array($given['date_format'] ?? null, self::DATE_FORMATS, true) ? $given['date_format'] : 'short',
            'date_pattern' => $pattern !== '' ? $pattern : null,
            'locale' => preg_match(self::LOCALE_PATTERN, $locale) === 1 ? $locale : null,
            'size' => in_array($given['size'] ?? null, self::HEADER_SIZES, true) ? $given['size'] : 'm',
        ];
    }

    protected function casts(): array
    {
        return [
            'aspect' => ScreenAspect::class,
            'orientation' => ScreenOrientation::class,
            'fit' => ScreenFit::class,
            'margin' => ScreenSpacing::class,
            'gap' => ScreenSpacing::class,
            'show_header' => 'boolean',
            'show_clock' => 'boolean',
            'theme' => ScreenTheme::class,
            'cols' => 'integer',
            'rows' => 'integer',
            'header' => 'array',
            'scope' => 'array',
            'widgets' => 'array',
            'share_key' => 'encrypted',
            'share_titles' => 'boolean',
        ];
    }

    /** @return HasMany<ScreenVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ScreenVersion::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** The share link, while the screen is shared. */
    public function shareUrl(): ?string
    {
        return $this->share_key === null ? null : route('screens.shared', $this->share_key);
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

        $local = ($datetime ?? now())->setTimezone($this->timezone ?? config('app.timezone'));
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
