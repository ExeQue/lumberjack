<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Mcp\Enums\McpScope;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpToken>
 */
class McpTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => 'claude-local',
            'token_hash' => McpToken::hash('lj_mcp_'.Str::random(32)),
            'scope' => McpScope::Write,
        ];
    }

    /**
     * A token only works while the person who made it administers its team.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (McpToken $token): void {
            if ($token->user !== null && $token->team->roleOf($token->user) === null) {
                $token->team->members()->attach($token->user, ['role' => TeamRole::Admin->value]);
            }
        });
    }

    public function withToken(string $plain): static
    {
        return $this->state(fn () => ['token_hash' => McpToken::hash($plain)]);
    }

    public function designsScreens(): static
    {
        return $this->state(fn () => ['can_design_screens' => true]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['scope' => McpScope::Read]);
    }
}
