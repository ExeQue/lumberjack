<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Repositories\Models\ProjectRepository;
use App\Domains\Retention\Models\RetentionPolicy;
use App\Domains\Schedule\Models\ScheduledTask;
use App\Domains\SourceMaps\Models\SourceMap;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $issue_prefix
 * @property int $last_issue_number
 * @property string $token_hash
 * @property string $token_last_four
 * @property string $public_key
 * @property list<string>|null $allowed_origins
 * @property list<string>|null $scrub_keys
 * @property string|null $stats_key
 * @property bool $stats_include_titles
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'name', 'slug', 'issue_prefix', 'token_hash', 'token_last_four', 'public_key', 'allowed_origins', 'scrub_keys', 'stats_key', 'stats_include_titles'])]
#[Hidden(['token_hash'])]
#[UseFactory(ProjectFactory::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
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
            'allowed_origins' => 'array',
            'scrub_keys' => 'array',
            'stats_include_titles' => 'boolean',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<LogChannel, $this> */
    public function logChannels(): HasMany
    {
        return $this->hasMany(LogChannel::class);
    }

    /**
     * What issue numbers start with, e.g. DEMO in DEMO-42: from the slug unless set.
     */
    public function issuePrefix(): string
    {
        return $this->issue_prefix ?: (mb_substr(strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $this->slug)), 0, 8) ?: 'ISSUE');
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('name');
    }

    /** @return HasMany<ScheduledTask, $this> */
    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    /** @return HasMany<HealthCheck, $this> */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    /** @return HasMany<DataSourceConnection, $this> */
    public function dataSourceConnections(): HasMany
    {
        return $this->hasMany(DataSourceConnection::class);
    }

    /**
     * The Umami websites the project reads, one connection each, by name.
     *
     * @return HasMany<DataSourceConnection, $this>
     */
    public function umamiConnections(): HasMany
    {
        return $this->hasMany(DataSourceConnection::class)->where('kind', ConnectionKind::Umami)->orderBy('name')->orderBy('id');
    }

    /**
     * The mosparo projects it reads, one connection each, by name.
     *
     * @return HasMany<DataSourceConnection, $this>
     */
    public function mosparoConnections(): HasMany
    {
        return $this->hasMany(DataSourceConnection::class)->where('kind', ConnectionKind::Mosparo)->orderBy('name')->orderBy('id');
    }

    /**
     * The GitHub repositories it reads, one connection each, by name.
     *
     * @return HasMany<DataSourceConnection, $this>
     */
    public function gitHubConnections(): HasMany
    {
        return $this->hasMany(DataSourceConnection::class)->where('kind', ConnectionKind::GitHub)->orderBy('name')->orderBy('id');
    }

    /**
     * The Vipps MobilePay ledgers it reads, one connection each, by name.
     *
     * @return HasMany<DataSourceConnection, $this>
     */
    public function mobilePayConnections(): HasMany
    {
        return $this->hasMany(DataSourceConnection::class)->where('kind', ConnectionKind::MobilePay)->orderBy('name')->orderBy('id');
    }

    /** @return HasMany<DataSource, $this> */
    public function dataSources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }

    /** @return HasMany<ProjectAgent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(ProjectAgent::class);
    }

    /** @return HasMany<SourceMap, $this> */
    public function sourceMaps(): HasMany
    {
        return $this->hasMany(SourceMap::class);
    }

    /** @return HasOne<ProjectRepository, $this> */
    public function repository(): HasOne
    {
        return $this->hasOne(ProjectRepository::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * Its own rules, each overriding the team's of that type.
     *
     * @return HasMany<AlertRule, $this>
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    /** @return HasMany<RetentionPolicy, $this> */
    public function retentionPolicies(): HasMany
    {
        return $this->hasMany(RetentionPolicy::class);
    }

    public function allowsOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        return in_array(rtrim($origin, '/'), array_map(fn (string $o): string => rtrim($o, '/'), $this->allowed_origins ?? []), true);
    }
}
