<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Support;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * The token behind the current MCP request and the projects it may see.
 */
class McpContext
{
    private ?McpToken $token = null;

    /** @var Collection<int, Project>|null */
    private ?Collection $projects = null;

    public function authenticate(McpToken $token): void
    {
        $this->token = $token;
        $this->projects = $token->projects()->with('team')->get();
    }

    public function token(): McpToken
    {
        return $this->token ?? throw new LogicException('No MCP token is authenticated.');
    }

    /**
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return $this->projects ?? new Collection();
    }

    /**
     * The named project, or the only one when the token covers exactly one.
     */
    public function project(?string $slug): ?Project
    {
        if ($slug === null || $slug === '') {
            return $this->projects()->count() === 1 ? $this->projects()->first() : null;
        }

        return $this->projects()->first(fn (Project $p): bool => $p->slug === $slug || $p->uuid === $slug);
    }
}
