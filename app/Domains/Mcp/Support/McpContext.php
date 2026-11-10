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
        $this->projects = $token->readableProjects();
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

    public function isAuthenticated(): bool
    {
        return $this->token !== null;
    }

    /**
     * The token's projects in which it may use the tool.
     *
     * @return Collection<int, Project>
     */
    public function projectsFor(string $tool): Collection
    {
        return $this->projects()->filter(fn (Project $project): bool => $this->token()->permits($tool, $project))->values();
    }
}
