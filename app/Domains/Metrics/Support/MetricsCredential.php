<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Support;

use App\Domains\Mcp\Models\McpToken;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Who is asking the metrics API: a team token (MCP token) for the projects where it may
 * use get_metric_series, or a project's public stats key for that one project, aggregates only.
 */
class MetricsCredential
{
    /** Seconds an identical question is answered from the cache. */
    public const int CACHE_SECONDS = 30;

    private ?Project $publicProject = null;

    /** @var Collection<int, Project>|null */
    private ?Collection $projects = null;

    private ?McpToken $token = null;

    public function authenticateToken(McpToken $token): void
    {
        $this->token = $token;
        $this->projects = $token->readableProjects()
            ->filter(fn (Project $project): bool => $token->permits('get_metric_series', $project))
            ->values();
    }

    public function authenticatePublic(Project $project): void
    {
        $this->publicProject = $project;
        $this->projects = new Collection([$project]);
    }

    /**
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return $this->projects ?? throw new LogicException('No metrics credential is authenticated.');
    }

    /**
     * A project the credential reads, by slug or uuid.
     */
    public function project(string $reference): ?Project
    {
        return $this->projects()->first(fn (Project $p): bool => $p->slug === $reference || $p->uuid === $reference);
    }

    /**
     * Whether the credential may list the project's issues: a token only where it may read
     * issues; public stats as they are set up.
     */
    public function readsIssues(Project $project): bool
    {
        return $this->token === null || $this->token->permits('list_issues', $project);
    }

    public function isPublic(): bool
    {
        return $this->publicProject !== null;
    }

    public function includesTitles(): bool
    {
        return $this->publicProject === null || $this->publicProject->stats_include_titles;
    }

    /**
     * A short-lived answer shared by every credential that would see the same thing: the
     * same projects, as a token or as public stats (with or without issue titles).
     *
     * @template T
     *
     * @param  array<array-key, mixed>  $args
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $name, array $args, Closure $compute): mixed
    {
        $view = $this->isPublic() ? 'public:'.($this->includesTitles() ? 'titles' : 'plain') : 'token';

        return Cache::remember('metrics-api:'.$name.':'.$view.':'.md5(serialize($args)), self::CACHE_SECONDS, $compute);
    }
}
