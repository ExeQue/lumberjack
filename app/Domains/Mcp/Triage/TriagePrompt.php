<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Triage;

use App\Domains\Projects\Models\Project;

/**
 * What an agent is asked to do on each triage run, the same for every provider.
 */
final class TriagePrompt
{
    /** The Lumberjack tools the agent needs; nothing that administers teams or projects. */
    public const array TOOLS = [
        'triage_inbox', 'get_issue', 'list_occurrences', 'get_trace', 'search_logs', 'list_members',
        'add_issue_note', 'set_issue_labels', 'set_issue_status', 'create_repo_issue',
    ];

    public function render(Project $project): string
    {
        return strtr(self::TEMPLATE, ['{slug}' => $project->slug]);
    }

    /**
     * For a GitHub issue that Lumberjack opened, when it is opened or someone
     * comments on it. {number} is filled in by the caller, e.g. a workflow expression.
     */
    public function forRepoIssue(Project $project, string $number): string
    {
        return strtr(self::REPO_ISSUE, ['{slug}' => $project->slug, '{number}' => $number]);
    }

    private const string REPO_ISSUE = <<<'TEXT'
GitHub issue #{number} in this repository carries the "lumberjack" label: Lumberjack opened it for an
error in the Lumberjack project "{slug}", and it was just opened or commented on. This repository is its code.
Change code only to open a pull request, as step 2 describes.

1. Read it with gh issue view {number} --comments. Its body links to the Lumberjack issue
   (.../issues/<id>), and its title starts with the issue's number, e.g. [DEMO-42]; give either to
   get_issue, and use get_trace, list_occurrences and search_logs from there.
2. If it was just opened: find the cause in the code (git log -S, git show, git blame on the in_app frames),
   and answer with gh issue comment {number}: the probable cause with file:line and a suggested fix.
   If you are sure and the fix is small, or a comment asks for it, also prepare a draft pull request:
   git checkout -b lumberjack/<reference>, the smallest change plus a test when the repository has
   tests, git push -u origin lumberjack/<reference>, and gh pr create --draft with "Fixes #{number}"
   in its body. Never push to the default branch and never merge. Link it in your comment.
3. If someone commented: answer what they asked, with gh issue comment {number}. Do not answer your own
   comments, and stay quiet if the comment needs no answer.
4. Keep Lumberjack in step: add_issue_note on the Lumberjack issue with a one-line summary of what you
   found or answered, and set_issue_labels if the area or severity became clear.
5. Never resolve the Lumberjack issue: a fix is only done once it is deployed.
TEXT;

    private const string TEMPLATE = <<<'TEXT'
You triage errors for the Lumberjack project "{slug}". This repository is its code.
Use only the lumberjack MCP tools to read and change issues. Change code only as step 3 describes.

1. Call triage_inbox, and again while has_more is true. It holds the open issues you have not handled
   yet: write a note on every issue you look at, even a single line when you ignore it, because that
   note marks it handled. An issue you leave without a note comes back on the next run.
2. For each entry in "mentions": someone tagged you in a note and asks for something.
   Do it, then answer with add_issue_note on that issue, tagging the author back.
3. For each issue in "issues", new or regressed:
   - Read the stacktrace and context of its latest occurrence. Use get_trace, list_occurrences
     and search_logs when they help.
   - Find the cause in the code: read the frames marked in_app, and use git log -S,
     git show and git blame to find the change that brought it in.
   - add_issue_note with a one-sentence summary on the first line, then: probable cause
     with file:line, who or what is affected, a suggested fix, and severity
     (critical, high, medium or low).
   - set_issue_labels: keep the labels it has, add the severity and an area such as
     "payments" or "checkout".
   - Noise (a bot, a client error the app handles, a known third-party outage):
     set_issue_status ignored, with a note saying why.
   - A real bug with a clear cause: create_repo_issue, if the project has a repository.
   - If you are sure of the cause and the fix is small and contained, prepare it for a developer
     to test: see "Opening a pull request" below.
4. Never resolve an issue: a fix is only done once it is deployed.
5. When there is nothing new, stop without writing anything.

Opening a pull request
- Only when you are sure; when in doubt, describe the fix in the note instead.
- <reference> is the issue's number, e.g. DEMO-42; every issue summary carries it.
- git checkout -b lumberjack/<reference>, make the smallest change that fixes the cause, and add or
  adjust a test for it when the repository has tests.
- Commit with a message that names the error, git push -u origin lumberjack/<reference>, and open a
  draft with gh pr create --draft: the cause, the change, how to test it, and a link to the issue.
- Never push to the default branch and never merge. Put the pull request's link in your note.
TEXT;
}
