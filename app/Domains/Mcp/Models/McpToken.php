<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Models;

use App\Domains\Mcp\Enums\McpScope;
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
 * @property McpScope $scope
 * @property bool $can_design_screens
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['team_id', 'user_id', 'name', 'token_hash', 'scope', 'can_design_screens', 'expires_at', 'last_used_at'])]
#[Hidden(['token_hash'])]
#[UseFactory(McpTokenFactory::class)]
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scope' => McpScope::class,
            'can_design_screens' => 'boolean',
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

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
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
     * The projects the token reads: those picked for it, still in its team (a project moved
     * to another team is no longer the token's), that its maker may view.
     *
     * @return Collection<int, Project>
     */
    public function readableProjects(): Collection
    {
        return $this->projects()->where('projects.team_id', $this->team_id)->with('team')->orderBy('projects.name')->get()
            ->filter(fn (Project $project): bool => $this->allows('view', $project))
            ->values();
    }

    public function canWrite(): bool
    {
        return $this->scope === McpScope::Write;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
