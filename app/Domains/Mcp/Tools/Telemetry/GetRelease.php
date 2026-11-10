<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Telemetry;

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Domains\Releases\Queries\ReleaseHealthQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[RequiresPermission(McpArea::Telemetry, McpLevel::Read)]
class GetRelease extends Tool
{
    use UsesMcpContext;

    protected string $name = 'get_release';

    protected string $description = 'One release seen in the period, as list_releases shows it, with up to 25 issues it brought (issues.new) and up to 25 it reopened (issues.regressed), the most frequent first. issues is null when the token may not read issues in the project.';

    public function __construct(
        private readonly ReleaseHealthQuery $releases,
        private readonly IssuePresenter $presenter,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'release' => $schema->string()->required()->description('The release name, as list_releases shows it.'),
            'period' => $schema->string()->enum(['1h', '24h', '7d', '30d'])->description('Defaults to 7d.'),
            'environment' => $schema->string(),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $validated = $request->validate(['release' => ['required', 'string', 'max:64']]);
        $period = Period::tryFrom((string) $request->get('period')) ?? Period::Week;
        $environment = (string) $request->get('environment', '');
        $environment = $environment !== '' ? $environment : null;
        $release = collect($this->releases->get($project->id, $period, $environment))->firstWhere('release', $validated['release']);

        if ($release === null) {
            return Response::error('No such release in this period; see list_releases, or pass a longer period.');
        }

        $present = fn (Issue $issue): array => $this->presenter->summary($issue->setRelation('project', $project));
        // Issues belong to their own area: left out where the token may not read them.
        $readsIssues = $this->context()->token()->permits('get_issue', $project);

        return Response::json([
            ...ListReleases::present($release),
            'issues' => ! $readsIssues ? null : [
                'new' => $this->releases->newIssues($project->id, $period, $environment, $release['release'])->map($present)->values()->all(),
                'regressed' => $this->releases->regressedIssues($project->id, $period, $environment, $release['release'])->map($present)->values()->all(),
            ],
        ]);
    }
}
