<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Mcp\Enums\McpScope;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Teams\Models\Team;
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
            'name' => 'claude-local',
            'token_hash' => McpToken::hash('lj_mcp_'.Str::random(32)),
            'scope' => McpScope::Write,
        ];
    }

    public function withToken(string $plain): static
    {
        return $this->state(fn () => ['token_hash' => McpToken::hash($plain)]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['scope' => McpScope::Read]);
    }
}
