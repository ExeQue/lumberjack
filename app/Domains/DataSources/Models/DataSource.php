<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Models;

use App\Domains\DataSources\Eloquent\Builders\DataSourceBuilder;
use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\DataSourceFactory;
use Illuminate\Contracts\Encryption\DecryptException;
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
 * An external endpoint of a project that Lumberjack fetches every interval, for screen
 * widgets to read a number or rows from: JSON with JSONPath, Prometheus text with a
 * selector, or an iCalendar feed for a calendar list (format). The latest raw body is kept here; the parsed body of each fetch that
 * fits goes to data_source_fetches, for charts.
 * Header values are often secrets: they are stored encrypted and never leave the server.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $name
 * @property string $url
 * @property DataSourceFormat $format
 * @property list<array{name: string, value: string}>|null $headers
 * @property int $interval_minutes
 * @property int $timeout_s
 * @property CarbonImmutable|null $next_fetch_at
 * @property CarbonImmutable|null $last_fetched_at
 * @property CarbonImmutable|null $last_ok_at
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property string|null $last_body
 * @property string|null $key
 * @property string $environment
 * @property DataSourceShape|null $shape
 * @property int|null $cache_seconds
 * @property int|null $data_source_connection_id
 * @property-read DataSourceConnection|null $connection
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'name', 'url', 'format', 'headers', 'interval_minutes', 'timeout_s', 'next_fetch_at',
    'last_fetched_at', 'last_ok_at', 'last_status_code', 'last_error', 'last_body',
    'key', 'environment', 'shape', 'cache_seconds', 'data_source_connection_id', 'archived_at',
])]
#[Hidden(['headers', 'last_body'])]
#[UseFactory(DataSourceFactory::class)]
#[UseEloquentBuilder(DataSourceBuilder::class)]
class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = ['format' => 'json'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return HasMany<DataSourceWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(DataSourceWindow::class);
    }

    /** @return BelongsTo<DataSourceConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DataSourceConnection::class, 'data_source_connection_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'format' => DataSourceFormat::class,
            'headers' => 'encrypted:array',
            'next_fetch_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_ok_at' => 'datetime',
            'shape' => DataSourceShape::class,
            'cache_seconds' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The names of its headers, the only part of them shown.
     *
     * @return list<string>
     */
    public function headerNames(): array
    {
        try {
            return array_map(fn (array $h): string => $h['name'], $this->headers ?? []);
        } catch (DecryptException) {
            // Encrypted under another APP_KEY: the page still opens, and the fetch says why it fails.
            return [];
        }
    }

    /**
     * Its URL as shown: the query's keys, not their values, which may be keys of another
     * kind; for a calendar not its path either.
     */
    public function maskedUrl(): string
    {
        // A calendar's secret address (Google, Outlook, iCloud) carries its key in the path.
        $url = $this->format === DataSourceFormat::Ical ? (string) preg_replace('~^([a-z][a-z0-9+.-]*://[^/?#]+)/[^?#]+~i', '$1/***', $this->url) : $this->url;

        return (string) preg_replace_callback('/\?([^#]*)/', fn (array $m): string => '?'.implode('&', array_map(
            fn (string $pair): string => str_contains($pair, '=') ? strstr($pair, '=', true).'=***' : ($pair === '' ? '' : '***'),
            explode('&', $m[1]),
        )), $url, 1);
    }
}
