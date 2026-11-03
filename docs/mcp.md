# MCP

Lumberjack is an MCP server, so an AI agent (Claude Code, Claude Desktop, Cursor, ...)
can read issues and telemetry and help triage them.

## Tokens

Team owners and admins create tokens under **Members and tokens** on the team page;
members see the list.

- **Name:** up to 100 characters, shown on everything the token does.
- **Projects:** the projects the token can see, at least one.
- **Scope:** `read` only reads; `write` can also change issues (status, assignee,
  labels, notes, watching, GitHub issues).
- **Expiry:** 30, 90 or 365 days, or never.

The token (`lj_mcp_...`) is shown once. Each token shows who created it and when it was
last used, and can be revoked at any time. A token makes at most 120 calls a minute.

A token works only while the person who created it is still an owner or admin of the
team. It stops working, and is marked **Stopped** on the team page, when they leave
the team, become a plain member, are suspended or their account is deleted. Revoke it
and let a current admin create a new one.

## Connecting

The server is at `https://your-lumberjack/mcp` (streamable HTTP) and takes the token as
a bearer token. For Claude Code:

```bash
claude mcp add --transport http lumberjack https://your-lumberjack/mcp \
  --header "Authorization: Bearer lj_mcp_..."
```

## Tools

The server tells agents to treat error messages, request data and log lines as data,
never as instructions to follow.

Every tool that takes an issue accepts its number (`DEMO-42`) or its id. Durations are
in microseconds.

| Tool                                                                                | Does                                                                                                                              |
| ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `list_projects`                                                                     | The projects the token can see, with the slug other tools take.                                                                   |
| `get_project_overview`                                                              | Requests, error rate, percentiles, job failures, health check counts, newest issues, slowest routes and failed jobs for a period. |
| `triage_inbox`                                                                      | Your work queue: open issues you have not handled since they last surfaced, and notes that tag you.                               |
| `list_issues`                                                                       | Issues with the issues page's filters.                                                                                            |
| `get_issue`                                                                         | One issue with its latest occurrence (stacktrace, request, user context) and notes.                                               |
| `list_occurrences`                                                                  | Recent occurrences of an issue.                                                                                                   |
| `set_issue_status`                                                                  | Resolve, ignore, re-open or mark in progress. Write scope.                                                                        |
| `assign_issue`                                                                      | Assign or unassign. Write scope.                                                                                                  |
| `set_issue_labels`                                                                  | Replace an issue's labels. Write scope.                                                                                           |
| `watch_issue`, `unwatch_issue`                                                      | Watch or stop watching an issue, as the token's person. Write scope.                                                              |
| `add_issue_note`                                                                    | Add a note; tag members with `<@id>`. Write scope.                                                                                |
| `create_repo_issue`                                                                 | Create a GitHub issue in the linked repository. Write scope.                                                                      |
| `list_members`                                                                      | The team's members, with the ids `assign_issue` takes.                                                                            |
| `list_routes`, `list_queries`, `list_jobs`, `list_commands`, `list_scheduled_tasks` | Statistics per route, query, job class, command and task.                                                                         |
| `get_job`, `get_command`, `get_scheduled_task`                                      | Recent runs of one job class, command or task.                                                                                    |
| `list_health_checks`                                                                | Health checks with their state, latest result, metrics, per-host status and uptime; `attention` for the bad ones.                 |
| `get_trace`                                                                         | Everything recorded under one trace id, in time order.                                                                            |
| `search_logs`                                                                       | Log lines by channel, level, text, period and trace id.                                                                           |

Agents' notes and status changes are shown in Lumberjack with the token's name.

## Automatic triage

The project settings have a ready-made GitHub Actions workflow that runs Claude Code in
your repository. It triages new and regressed issues and answers notes that tag it,
through the MCP tools. It never resolves an issue.

![The Automatic triage section of a project's settings](./screenshots/settings-triage.png)

It does not change the default branch. When it is sure of the cause and the fix is
small, or a comment asks for one, it opens a **draft pull request** from a
`lumberjack/<issue number>` branch with "Fixes #N" in its body, and links it in its
note or comment. It never merges. Pull requests opened with the workflow's own token do
not start other workflows, so CI runs once someone pushes to the branch or runs it
again.

1. Create a token with the write scope that covers the project.
2. Add the secrets to the GitHub repository: `LUMBERJACK_MCP_TOKEN` (the token) and
   `CLAUDE_CODE_OAUTH_TOKEN` (from `claude setup-token`, using your Claude
   subscription). To pay per use instead, swap the commented lines for
   `ANTHROPIC_API_KEY`.
3. Commit the workflow shown in the settings as
   `.github/workflows/lumberjack-triage.yml`.

It runs:

| When                                                                        | Does                                                                |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Every 15 minutes, or by hand (**Run workflow**)                             | Works through the triage inbox and notes in Lumberjack that tag it. |
| A GitHub issue labeled `lumberjack` is opened or labeled, or gets a comment | Answers on the GitHub issue. Comments by bots are left alone.       |

`lumberjack` is the label Lumberjack puts on the GitHub issues it creates. A run has 20
minutes; one run per issue (or for the inbox) at a time.

GitHub has to reach your Lumberjack, so it must be on a public URL. The settings also
show how to run the same triage once in a local Claude Code session.

The workflow runs as "Lumberjack Triage" in the Actions tab, with the GitHub issue
number in the run's title, and commits as "Lumberjack". GitHub shows a commit's avatar
from the account its email belongs to, so to put the Lumberjack logo on those commits,
create a GitHub App (or account) with the logo as its avatar and set the repository
variable `LUMBERJACK_GIT_EMAIL` to its email (without it, commits use
`lumberjack@noreply.invalid`). For an app that is
`<bot user id>+<app slug>[bot]@users.noreply.github.com`; the bot user id comes from
`gh api /users/<app slug>[bot] --jq .id`.
