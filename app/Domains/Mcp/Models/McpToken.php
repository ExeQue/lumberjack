<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Models;

use App\Domains\Mcp\Casts\McpPermissionsCast;
use App\Domains\Mcp\Data\McpPermissions;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Support\McpToolCatalog;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Gate;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $name
 * @property string $token_hash
 * @property bool $all_projects
 * @property McpPermissions $permissions
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['team_id', 'user_id', 'name', 'token_hash', 'all_projects', 'permissions', 'expires_at', 'last_used_at'])]
#[Hidden(['token_hash'])]
#[UseFactory(McpTokenFactory::class)]
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    /**
     * Each covered project's changes, read once per token.
     *
     * @var array<int, McpPermissions>|null
     */
    private ?array $projectChanges = null;

    protected function casts(): array
    {
        return [
            'all_projects' => 'boolean',
            'permissions' => McpPermissionsCast::class,
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Project, $this, McpTokenProject> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->using(McpTokenProject::class)->withPivot('permissions');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A token acts for the team, but only while the person who made it may still make
     * one: it stops working when they leave the team, lose the admin role, are
     * suspended or deleted; a current admin makes a new one.
     */
    public function isUsable(): bool
    {
        $user = $this->user;

        return $user !== null && ! $user->isSuspended() && $user->canAdministerTeam($this->team);
    }

    /**
     * Whether the token may do this: it acts as the person who made it, through the same
     * policies as the app, and only while that person may still use it.
     */
    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->isUsable() && Gate::forUser($this->user)->allows($ability, $arguments);
    }

    /**
     * The projects the token reads: every project of its team when it covers them all
     * (new ones included), else those picked for it while they stay in its team; only
     * those its maker may view.
     *
     * @return Collection<int, Project>
     */
    public function readableProjects(): Collection
    {
        $projects = $this->all_projects
            ? $this->team->projects()->getQuery()
            : $this->projects()->where('projects.team_id', $this->team_id)->getQuery();

        return $projects->with('team')->orderBy('projects.name')->get()
            ->filter(fn (Project $project): bool => $this->allows('view', $project))
            ->values();
    }

    /**
     * Whether the token may use the tool, in the project when one is given. A single-tool
     * change wins over a level, and a project's change wins over Default; team-wide areas
     * only read Default. The maker's policies still apply on top of this.
     */
    public function permits(string $tool, ?Project $project = null): bool
    {
        $entry = app(McpToolCatalog::class)->find($tool);

        if ($entry === null) {
            return false;
        }

        if ($entry['area'] === null || $entry['level'] === null) {
            return true;
        }

        $area = $entry['area'];
        $default = $this->permissions->area($area);
        $override = $project !== null && ! $area->isTeamWide() ? $this->changesFor($project)->area($area) : null;

        return $override?->exception($tool)
            ?? ($override?->level?->includes($entry['level']))
            ?? $default?->exception($tool)
            ?? ($default->level ?? McpLevel::None)->includes($entry['level']);
    }

    /**
     * The token's level in an area, for a project when one is given; single-tool changes aside.
     */
    public function levelFor(McpArea $area, ?Project $project = null): McpLevel
    {
        $override = $project !== null && ! $area->isTeamWide() ? $this->changesFor($project)->level($area) : null;

        return $override ?? $this->permissions->level($area) ?? McpLevel::None;
    }

    /**
     * The token's changes to its Default for one project.
     */
    public function changesFor(Project $project): McpPermissions
    {
        $this->projectChanges ??= McpTokenProject::query()->where('mcp_token_id', $this->id)->whereNotNull('permissions')->get()
            ->mapWithKeys(fn (McpTokenProject $row): array => [$row->project_id => $row->permissions])->all();

        return $this->projectChanges[$project->id] ?? new McpPermissions();
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
