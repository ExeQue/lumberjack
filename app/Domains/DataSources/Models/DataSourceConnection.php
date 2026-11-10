<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Models;

use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\DataSourceConnectionFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One environment of an app that offers data sources: where Lumberjack pulls them and the
 * token it signs with. Set up here, as the app never reports anything itself.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $environment
 * @property string $url
 * @property string $timezone where its days begin, for the buckets of a series
 * @property string|null $pull_token
 * @property string|null $pull_token_previous
 * @property bool $uses_previous
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_ok_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $next_check_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'environment', 'url', 'timezone', 'pull_token', 'pull_token_previous', 'uses_previous', 'last_checked_at', 'last_ok_at', 'last_error', 'next_check_at'])]
#[Hidden(['pull_token', 'pull_token_previous'])]
#[UseFactory(DataSourceConnectionFactory::class)]
class DataSourceConnection extends Model
{
    /** @use HasFactory<DataSourceConnectionFactory> */
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
            'pull_token' => 'encrypted',
            'pull_token_previous' => 'encrypted',
            'uses_previous' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_ok_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<DataSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }

    /**
     * Whether pulls work: waiting before the app ever answered, failing after an error, else connected.
     *
     * @return 'waiting'|'failing'|'connected'
     */
    public function status(): string
    {
        return match (true) {
            $this->last_ok_at === null => 'waiting',
            $this->last_error !== null => 'failing',
            default => 'connected',
        };
    }

    /**
     * The tokens that can still be read. One saved under another APP_KEY counts as none,
     * so a changed key costs a rotation rather than the page.
     *
     * @return array{current?: string, previous?: string}
     */
    public function readableTokens(): array
    {
        $tokens = [];

        foreach (['current' => 'pull_token', 'previous' => 'pull_token_previous'] as $name => $attribute) {
            try {
                if (is_string($token = $this->getAttribute($attribute))) {
                    $tokens[$name] = $token;
                }
            } catch (DecryptException) {
                continue;
            }
        }

        return $tokens;
    }

    /** The token the app was last seen to hold. */
    public function activeToken(): ?string
    {
        $tokens = $this->readableTokens();

        return $this->uses_previous ? ($tokens['previous'] ?? null) : ($tokens['current'] ?? null);
    }
}
