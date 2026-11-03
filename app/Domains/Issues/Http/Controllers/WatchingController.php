<?php

declare(strict_types=1);

namespace App\Domains\Issues\Http\Controllers;

use App\Domains\Issues\Actions\UnwatchIssueAction;
use App\Domains\Issues\Actions\WatchIssueAction;
use App\Domains\Issues\Http\Requests\BulkWatchIssuesRequest;
use App\Domains\Issues\Http\Requests\StopWatchingRequest;
use App\Domains\Issues\Http\Requests\WatchIssueRequest;
use App\Domains\Issues\Models\Issue;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WatchingController
{
    public function update(WatchIssueRequest $request, Project $project, Issue $issue, WatchIssueAction $watch, UnwatchIssueAction $unwatch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->boolean('watching') ? $watch->execute($issue, $user) : $unwatch->execute($issue, $user);

        return back();
    }

    public function bulk(BulkWatchIssuesRequest $request, Project $project, WatchIssueAction $watch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $issues = Issue::query()->where('project_id', $project->id)->whereIn('uuid', $request->issueUuids())->get();
        $issues->each(fn (Issue $issue) => $watch->execute($issue, $user));
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('issues.bulk.watching', $issues->count())]);

        return back();
    }

    /**
     * The page the "Stop watching" link opens. It asks before stopping, so a mail
     * scanner that follows every link does not stop it; the mail client's one-click
     * unsubscribe posts straight to stop().
     */
    public function confirm(StopWatchingRequest $request, Issue $issue): View
    {
        $request->watcher();

        return view('watching.stop', ['issue' => $issue->load('project'), 'stopped' => false, 'action' => $request->fullUrl()]);
    }

    public function stop(StopWatchingRequest $request, Issue $issue, UnwatchIssueAction $unwatch): View
    {
        $unwatch->execute($issue, $request->watcher());

        return view('watching.stop', ['issue' => $issue->load('project'), 'stopped' => true, 'action' => null]);
    }
}
