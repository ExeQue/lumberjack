<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Concerns;

use App\Domains\Issues\Models\Issue;
use App\Domains\Mcp\Support\McpContext;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait UsesMcpContext
{
    protected function context(): McpContext
    {
        return app(McpContext::class);
    }

    protected function projectArgument(JsonSchema $schema): mixed
    {
        return $schema->string()->description('Project slug. Required when the token covers more than one project; see list_projects.');
    }

    protected function project(Request $request): Project|Response
    {
        $project = $this->context()->project($request->get('project'));

        return $project ?? Response::error('Unknown project. Pass "project" with one of: '.$this->context()->projects()->pluck('slug')->implode(', '));
    }

    protected function issue(Request $request): Issue|Response
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

        return $issue ?? Response::error('Unknown issue for this token; give its id or its number, e.g. DEMO-42.');
    }

    protected function denyUnlessWrite(): ?Response
    {
        return $this->context()->token()->canWrite() ? null : Response::error('This token has the read scope; the action needs write.');
    }
}
