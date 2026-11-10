<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Support\McpContext;
use App\Domains\Mcp\Support\McpToolCatalog;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * The token behind the request, and the one place a tool's permission is checked: a tool
 * is only listed when the token may use it somewhere, and every project, issue or model
 * it reaches goes through project(), projects(), issue() or authorize().
 */
trait UsesMcpContext
{
    protected function context(): McpContext
    {
        return app(McpContext::class);
    }

    /**
     * Listed only when the token may use the tool: in Default for a team-wide area, else in
     * at least one of its projects.
     */
    public function shouldRegister(McpContext $context): bool
    {
        if (! $context->isAuthenticated()) {
            return false;
        }

        $area = app(McpToolCatalog::class)->find($this->name())['area'] ?? null;

        return $area === null || $area->isTeamWide()
            ? $context->token()->permits($this->name())
            : $context->projectsFor($this->name())->isNotEmpty();
    }

    protected function projectArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->description('Project slug. Required when the token covers more than one project; see list_projects.');
    }

    /**
     * The token's projects in which it may use this tool.
     *
     * @return Collection<int, Project>
     */
    protected function projects(): Collection
    {
        return $this->context()->projectsFor($this->name());
    }

    /**
     * The project the request names, or the only one the tool may use.
     */
    protected function project(Request $request): Project|Response
    {
        $slug = (string) $request->get('project', '');
        $granted = $this->projects();

        if ($slug === '') {
            return $granted->count() === 1
                ? $granted->first()
                : Response::error('Pass "project" with one of: '.$granted->pluck('slug')->implode(', '));
        }

        $project = $this->context()->projects()->first(fn (Project $p): bool => $p->slug === $slug || $p->uuid === $slug);

        if ($project === null) {
            return Response::error('Unknown project. Pass "project" with one of: '.$granted->pluck('slug')->implode(', '));
        }

        return $this->deniedIn($project) ?? $project;
    }

    /**
     * The issue the request names, within the token's projects, if the token may use this
     * tool there and its maker may do $ability on it ("view" to read or watch, "update" to change it).
     */
    protected function issue(Request $request, string $ability = 'view'): Issue|Response
    {
        $given = trim((string) $request->get('issue'));
        $projects = $this->context()->projects();

        // An issue id, or its number as DEMO-42 within the projects the token covers.
        $reference = preg_match('/^([a-z0-9]+)-(\d+)$/i', $given, $m) === 1
            ? $projects->first(fn (Project $p): bool => strcasecmp($p->issuePrefix(), $m[1]) === 0)
            : null;

        $issue = Issue::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->when($reference !== null)->where(['project_id' => $reference?->id, 'number' => (int) ($m[2] ?? 0)])
            ->when($reference === null)->where('uuid', $given)
            ->with(['project', 'assignee'])
            ->first();

        if ($issue === null) {
            return Response::error('Unknown issue for this token; give its id or its number, e.g. DEMO-42.');
        }

        return $this->deniedIn($issue->project) ?? $this->authorize($ability, $issue) ?? $issue;
    }

    /**
     * Null when the token's maker may do $ability on the model, else the refusal to return.
     */
    protected function authorize(string $ability, Model $model): ?Response
    {
        return $this->context()->token()->allows($ability, $model)
            ? null
            : Response::error('The token\'s owner may not '.($ability === 'view' ? 'see' : 'change').' this '.str(class_basename($model))->snake(' ').'.');
    }

    /**
     * Null when the token may use this tool in the project, else the refusal to return.
     */
    protected function deniedIn(Project $project): ?Response
    {
        return $this->context()->token()->permits($this->name(), $project)
            ? null
            : Response::error('This token may not use '.$this->name().' in project '.$project->slug.'.');
    }
}
