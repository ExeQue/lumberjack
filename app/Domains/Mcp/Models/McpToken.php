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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property string $name
 * @property string $token_hash
 * @property McpScope $scope
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['team_id', 'user_id', 'name', 'token_hash', 'scope', 'expires_at', 'last_used_at'])]
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

    public function canWrite(): bool
    {
        return $this->scope === McpScope::Write;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
