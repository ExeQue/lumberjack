<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use App\Domains\Issues\Actions\DetectEscalationAction;
use App\Domains\Issues\Eloquent\Builders\IssueBuilder;
use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property int|null $number
 * @property string $fingerprint
 * @property IssueSource $source
 * @property IssueStatus $status
 * @property string $title
 * @property string $message
 * @property string|null $file
 * @property int|null $line
 * @property string|null $log_channel
 * @property string|null $last_release
 * @property string|null $first_release
 * @property string|null $first_environment
 * @property string|null $external_issue_provider
 * @property string|null $external_issue_url
 * @property int $occurrences_count
 * @property int $users_count
 * @property-read int|null $notes_count
 * @property int|null $assigned_user_id
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable $surfaced_at
 * @property CarbonImmutable|null $regressed_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $in_progress_at
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $ignore_until_at
 * @property int|null $ignore_until_count
 * @property bool $ignore_until_escalation
 * @property CarbonImmutable|null $returned_at
 * @property CarbonImmutable|null $ignored_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id', 'number', 'fingerprint', 'source', 'status', 'title', 'message', 'file', 'line', 'log_channel',
    'last_release', 'first_release', 'first_environment', 'external_issue_provider', 'external_issue_url', 'occurrences_count', 'users_count', 'assigned_user_id', 'first_seen_at', 'last_seen_at', 'surfaced_at',
    'regressed_at', 'in_progress_at', 'escalated_at', 'ignore_until_at', 'ignore_until_count', 'ignore_until_escalation', 'returned_at', 'resolved_at', 'ignored_at',
])]
#[UseEloquentBuilder(IssueBuilder::class)]
#[UseFactory(IssueFactory::class)]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
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
            'source' => IssueSource::class,
            'status' => IssueStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'surfaced_at' => 'datetime',
            'regressed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'in_progress_at' => 'datetime',
            'escalated_at' => 'datetime',
            'ignore_until_at' => 'datetime',
            'ignore_until_escalation' => 'boolean',
            'returned_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return HasMany<Occurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }

    /** @return HasMany<IssueEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IssueEvent::class);
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->orderBy('name');
    }

    /**
     * Who watches it now; IssueWatcher rows also remember who stopped.
     *
     * @return BelongsToMany<User, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'issue_watchers')->withPivot('reason')->wherePivotNull('unwatched_at');
    }

    /** @return HasMany<IssueNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(IssueNote::class);
    }

    /**
     * The issue's number in its project, e.g. DEMO-42.
     */
    public function reference(): string
    {
        return $this->project->issuePrefix().'-'.$this->number;
    }

    /**
     * Happening far more often than usual, lately; see DetectEscalationAction.
     */
    public function isEscalating(): bool
    {
        return $this->status->isOpen()
            && $this->escalated_at !== null
            && $this->escalated_at->greaterThan(now()->subMinutes(DetectEscalationAction::ACTIVE_MINUTES));
    }

    public function isRegressed(): bool
    {
        return $this->status === IssueStatus::Open && $this->regressed_at !== null;
    }
}
