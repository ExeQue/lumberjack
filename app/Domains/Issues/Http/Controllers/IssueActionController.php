<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Controllers;

use App\Domains\Issues\Actions\AddIssueNoteAction;
use App\Domains\Issues\Actions\AssignIssueAction;
use App\Domains\Issues\Actions\BulkAssignIssuesAction;
use App\Domains\Issues\Actions\BulkChangeIssueStatusAction;
use App\Domains\Issues\Actions\BulkLabelIssuesAction;
use App\Domains\Issues\Actions\ChangeIssueStatusAction;
use App\Domains\Issues\Actions\SetIssueLabelsAction;
use App\Domains\Issues\Data\IgnoreCondition;
use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Issues\Exceptions\IssueExceptionInterface;
use App\Domains\Issues\Http\Requests\AssignIssueRequest;
use App\Domains\Issues\Http\Requests\BulkAssignIssuesRequest;
use App\Domains\Issues\Http\Requests\BulkIssueLabelsRequest;
use App\Domains\Issues\Http\Requests\BulkIssueStatusRequest;
use App\Domains\Issues\Http\Requests\SetIssueLabelsRequest;
use App\Domains\Issues\Http\Requests\StoreIssueNoteRequest;
use App\Domains\Issues\Http\Requests\UpdateIssueStatusRequest;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Actions\CreateRepoIssueAction;
use App\Domains\Repositories\Exceptions\RepositoryExceptionInterface;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IssueActionController
{
    public function status(UpdateIssueStatusRequest $request, Project $project, Issue $issue, ChangeIssueStatusAction $action): RedirectResponse
    {
        // A comment given with the status change, e.g. why it is resolved, becomes a note.
        $action->execute(
            $issue,
            IssueStatus::from($request->string('status')->toString()),
            $request->user(),
            $request->filled('note') ? $request->string('note')->toString() : null,
            until: IgnoreCondition::fromChoice($request->input('until')),
        );

        return back();
    }

    public function bulkStatus(BulkIssueStatusRequest $request, Project $project, BulkChangeIssueStatusAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $status = IssueStatus::from($request->string('status')->toString());
        $count = $action->execute($project, $request->issueUuids(), $status, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('issues.bulk.'.$status->value, $count)]);

        return back();
    }

    public function bulkAssign(BulkAssignIssuesRequest $request, Project $project, BulkAssignIssuesAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $assignee = $request->filled('user_id') ? User::query()->find($request->integer('user_id')) : null;

        try {
            $count = $action->execute($project, $request->issueUuids(), $assignee, $user);
        } catch (IssueExceptionInterface $e) {
            return back()->withErrors(['user_id' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $assignee !== null
            ? trans_choice('issues.bulk.assigned', $count, ['name' => $assignee->name])
            : trans_choice('issues.bulk.unassigned', $count)]);

        return back();
    }

    public function bulkLabels(BulkIssueLabelsRequest $request, Project $project, BulkLabelIssuesAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $label = trim($request->string('label')->toString());
        $remove = $request->boolean('remove');
        $count = $action->execute($project, $request->issueUuids(), $label, $remove, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice($remove ? 'issues.bulk.label_removed' : 'issues.bulk.label_added', $count, ['label' => $label])]);

        return back();
    }

    public function assign(AssignIssueRequest $request, Project $project, Issue $issue, AssignIssueAction $action): RedirectResponse
    {
        try {
            $action->execute($issue, $request->filled('user_id') ? User::query()->find($request->integer('user_id')) : null, $request->user());
        } catch (IssueExceptionInterface $e) {
            return back()->withErrors(['user_id' => $e->getMessage()]);
        }

        return back();
    }

    public function labels(SetIssueLabelsRequest $request, Project $project, Issue $issue, SetIssueLabelsAction $action): RedirectResponse
    {
        $action->execute($issue, $request->labels(), $request->user());

        return back();
    }

    public function note(StoreIssueNoteRequest $request, Project $project, Issue $issue, AddIssueNoteAction $action): RedirectResponse
    {
        $action->execute($issue, $request->string('body')->toString(), $request->user());

        return back();
    }

    public function repoIssue(Request $request, Project $project, Issue $issue, CreateRepoIssueAction $action): RedirectResponse
    {
        Gate::authorize('update', $issue);

        try {
            $action->execute($issue, by: $request->user());
        } catch (RepositoryExceptionInterface $e) {
            return back()->withErrors(['repository' => $e->getMessage()]);
        }

        return back()->with('status', __('issues.repo_issue_created'));
    }
}
