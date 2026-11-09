<?php

declare(strict_types=1);

namespace App\Domains\Health\Models;

use App\Domains\Health\Eloquent\Builders\HealthCheckBuilder;
use App\Domains\Health\Enums\HealthCheckSource;
use App\Domains\Health\Enums\HealthCheckType;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\HealthCheckFactory;
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
 * One health check. A reported check is identified by project, environment and slug;
 * its state lives per host in {@see HealthCheckHost}, because one slug reported by
 * three servers must be able to fail on one of them while the others pass. The
 * check's own status is the worst of its hosts that still report.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property HealthCheckSource $source
 * @property HealthCheckType|null $type
 * @property string $environment
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $description
 * @property array<string, mixed>|null $config
 * @property int|null $interval_minutes
 * @property int $degrade_after
 * @property int|null $down_after null for the default
 * @property int $recover_after
 * @property bool $thresholds_overridden
 * @property HealthStatus|null $status
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $last_result_at
 * @property CarbonImmutable|null $next_run_at
 * @property string|null $ping_token
 * @property CarbonImmutable|null $last_ping_at
 * @property bool $muted
 * @property CarbonImmutable|null $archived_at
 * @property HealthStatus|null $alerted_status
 * @property CarbonImmutable|null $alerted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'source', 'type', 'environment', 'slug', 'name', 'description', 'config', 'interval_minutes',
    'degrade_after', 'down_after', 'recover_after', 'thresholds_overridden', 'status', 'status_changed_at', 'last_result_at',
    'next_run_at', 'ping_token', 'last_ping_at', 'muted', 'archived_at', 'alerted_status', 'alerted_at',
])]
#[Hidden(['ping_token'])]
#[UseEloquentBuilder(HealthCheckBuilder::class)]
#[UseFactory(HealthCheckFactory::class)]
class HealthCheck extends Model
{
    /** @use HasFactory<HealthCheckFactory> */
    use HasFactory;
    use HasUuids;

    public const int DEFAULT_DEGRADE_AFTER = 2;

    public const int DEFAULT_RECOVER_AFTER = 5;

    public const int DEFAULT_DOWN_AFTER = 5;

    /** @var list<int> */
    public const array INTERVALS = [1, 5, 15, 60];

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
            'source' => HealthCheckSource::class,
            'type' => HealthCheckType::class,
            'config' => 'array',
            'thresholds_overridden' => 'boolean',
            'status' => HealthStatus::class,
            'status_changed_at' => 'datetime',
            'last_result_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'muted' => 'boolean',
            'archived_at' => 'datetime',
            'alerted_status' => HealthStatus::class,
            'alerted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<HealthCheckHost, $this> */
    public function hosts(): HasMany
    {
        return $this->hasMany(HealthCheckHost::class)->orderBy('host');
    }

    /** @return HasMany<HealthCheckResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(HealthCheckResult::class);
    }

    /** @return HasMany<HealthOutage, $this> */
    public function outages(): HasMany
    {
        return $this->hasMany(HealthOutage::class);
    }

    public function isReported(): bool
    {
        return $this->source === HealthCheckSource::Reported;
    }

    /**
     * Failures in a row before down; never fewer than degrade_after, so setting both
     * the same goes straight to down.
     */
    public function downAfter(): int
    {
        return max($this->down_after ?? self::DEFAULT_DOWN_AFTER, $this->degrade_after);
    }

    public function displayName(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return $this->slug !== null ? self::readableSlug($this->slug) : $this->nameFromSettings();
    }

    /** What a check Lumberjack runs looks at, as "SSL: example.com". A heartbeat looks at nothing, so it has a name. */
    private function nameFromSettings(): string
    {
        $host = (string) $this->setting('host');
        $port = (int) $this->setting('port');

        return match ($this->type) {
            HealthCheckType::Http => 'HTTP: '.parse_url((string) $this->setting('url'), PHP_URL_HOST),
            HealthCheckType::Ssl => 'SSL: '.$host.(in_array($port, [0, 443], true) ? '' : ':'.$port),
            HealthCheckType::Tcp => 'TCP: '.$host.':'.$port,
            HealthCheckType::Domain => 'Domain: '.$this->setting('domain'),
            HealthCheckType::Smtp => 'SMTP: '.$host.':'.$port,
            HealthCheckType::Dns => 'DNS: '.$this->setting('name').' ('.$this->setting('record_type', 'A').')',
            default => (string) $this->type?->value,
        };
    }

    /** "database:event-processing" reads as "Database: Event Processing", "disk:s3" as "Disk: S3". */
    private static function readableSlug(string $slug): string
    {
        /** @var array<string, string> $spelled */
        $spelled = config('health.spelled_words');

        return (string) preg_replace_callback(
            '/(?<![^ ])[^ :]+/',
            fn (array $word): string => $spelled[$word[0]] ?? ucfirst($word[0]),
            str_replace(':', ': ', strtr($slug, '-_', '  ')),
        );
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Its status as shown: the worst of the hosts that still report, or null when every
     * host has gone quiet (shown as stale) or nothing has come in yet.
     */
    public function liveStatus(): ?HealthStatus
    {
        return HealthStatus::worst($this->hosts->reject(fn (HealthCheckHost $h): bool => $this->isReported() && $h->isStale())->map(fn (HealthCheckHost $h): ?HealthStatus => $h->status));
    }

    /**
     * A reported check whose every host has stopped reporting: not down, just quiet.
     */
    public function isStale(): bool
    {
        return $this->isReported() && $this->hosts->isNotEmpty() && $this->hosts->every(fn (HealthCheckHost $h): bool => $h->isStale());
    }
}
