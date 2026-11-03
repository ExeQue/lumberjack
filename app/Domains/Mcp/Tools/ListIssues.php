<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools;

use App\Domains\Issues\Enums\IssueSource;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Presenters\IssuePresenter;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListIssues extends Tool
{
    use UsesMcpContext;

    protected string $name = 'list_issues';

    protected string $description = 'Lists issues with the same filters as the issues page: status, source, search and sort.';

    public function __construct(private readonly IssuePresenter $presenter)
    {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'status' => $schema->string()->enum(['open', 'in_progress', 'resolved', 'ignored'])->description('Defaults to open, which includes in_progress.'),
            'source' => $schema->string()->enum(['server', 'browser', 'log']),
            'search' => $schema->string()->description('Matches title or message.'),
            'label' => $schema->string()->description('Only issues with this label.'),
            'sort' => $schema->string()->enum(['last_seen', 'occurrences', 'users', 'first_seen'])->description('Defaults to last_seen.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Defaults to 25.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        $source = IssueSource::tryFrom((string) $request->get('source'));
        $search = (string) $request->get('search', '');
        $label = (string) $request->get('label', '');

        $issues = Issue::query()
            ->where('project_id', $project->id)
            ->when(($status = IssueStatus::tryFrom((string) $request->get('status')) ?? IssueStatus::Open) === IssueStatus::Open)->whereOpen()
            ->when($status !== IssueStatus::Open)->whereStatus($status)
            ->when($source !== null)->whereSource($source ?? IssueSource::Server)
            ->when($search !== '')->whereSearch($search)
            ->when($label !== '')->whereHasLabel($label)
            ->orderByDesc(match ($request->get('sort')) {
                'occurrences' => 'occurrences_count',
                'users' => 'users_count',
                'first_seen' => 'first_seen_at',
                default => 'last_seen_at',
            })
            ->with(['project', 'assignee', 'labels'])
            ->limit(min(100, max(1, (int) $request->get('limit', 25))))
            ->get();

        return Response::json(['issues' => $issues->map(fn (Issue $i): array => $this->presenter->summary($i))->all()]);
    }
}
