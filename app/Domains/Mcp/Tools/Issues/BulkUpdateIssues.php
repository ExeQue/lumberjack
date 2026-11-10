<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Tools\Issues;

use App\Domains\Issues\Actions\BulkAssignIssuesAction;
use App\Domains\Issues\Actions\BulkChangeIssueStatusAction;
use App\Domains\Issues\Actions\BulkLabelIssuesAction;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Exceptions\IssueExceptionInterface;
use App\Domains\Issues\Http\Requests\Concerns\TargetsSelectedIssues;
use App\Domains\Issues\Models\Label;
use App\Domains\Mcp\Attributes\RequiresPermission;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Tools\Concerns\UsesMcpContext;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The issues list's bulk bar over MCP: the same Actions and the same rule (every member
 * of the project may change its issues).
 */
#[RequiresPermission(McpArea::Issues, McpLevel::Write)]
class BulkUpdateIssues extends Tool
{
    use UsesMcpContext;

    protected string $name = 'bulk_update_issues';

    protected string $description = 'Changes up to 100 issues of one project at once: a status, an assignee (or unassign), and a label to add or remove. Pass the issue ids from list_issues and at least one change; returns how many issues each change touched.';

    public function __construct(
        private readonly BulkChangeIssueStatusAction $status,
        private readonly BulkAssignIssuesAction $assign,
        private readonly BulkLabelIssuesAction $labels,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $this->projectArgument($schema),
            'issues' => $schema->array()->items($schema->string())->description('Issue ids (uuids) from list_issues, all in this project. At most 100.')->required(),
            'status' => $schema->string()->enum(array_map(fn (IssueStatus $status): string => $status->value, IssueStatus::cases())),
            'user_id' => $schema->integer()->description('Assign them to this team member; see list_members.'),
            'unassign' => $schema->boolean()->description('Clear the assignee.'),
            'add_label' => $schema->string()->description('A label name; a new name creates the label.'),
            'remove_label' => $schema->string()->description('A label name.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $project = $this->project($request);

        if ($project instanceof Response) {
            return $project;
        }

        if (($denied = $this->authorize('view', $project)) !== null) {
            return $denied;
        }

        $input = $request->validate([
            ...TargetsSelectedIssues::issueRulesFor($project),
            'status' => ['nullable', Rule::enum(IssueStatus::class)],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'prohibited_if:unassign,true'],
            'unassign' => ['boolean'],
            'add_label' => ['nullable', 'string', 'max:'.Label::MAX_NAME],
            'remove_label' => ['nullable', 'string', 'max:'.Label::MAX_NAME],
        ]);
        $uuids = array_values(array_filter((array) $input['issues'], is_string(...)));
        $token = $this->context()->token();
        $changed = [];
        $assignee = isset($input['user_id']) ? User::query()->find((int) $input['user_id']) : null;

        // Refused before anything changes, so a bad assignee never leaves a half-done change.
        if ($assignee !== null && ! $assignee->belongsToTeam($project->team)) {
            return Response::error('That user is not on the team; see list_members.');
        }

        if (($input['status'] ?? null) !== null) {
            $changed['status'] = $this->status->execute($project, $uuids, IssueStatus::from((string) $input['status']), token: $token);
        }

        if (($input['user_id'] ?? null) !== null || (bool) ($input['unassign'] ?? false)) {
            try {
                $changed['assignee'] = $this->assign->execute($project, $uuids, $assignee, token: $token);
            } catch (IssueExceptionInterface $e) {
                return Response::error($e->getMessage());
            }
        }

        foreach (['add_label' => false, 'remove_label' => true] as $key => $remove) {
            $label = trim((string) ($input[$key] ?? ''));

            if ($label !== '') {
                $changed[$key] = $this->labels->execute($project, $uuids, $label, $remove, token: $token);
            }
        }

        if ($changed === []) {
            return Response::error('Pass at least one change: status, user_id, unassign, add_label or remove_label.');
        }

        return Response::json(['changed' => $changed]);
    }
}
