# Issues

Every exception, failed job, failed or missed scheduled task, failed command and error
log line becomes an issue. Occurrences with the same fingerprint are grouped under
one issue.

## Issue numbers

Each issue gets a number made of the project's prefix and a counter, like `DEMO-42`.
The prefix is chosen when the project is created (2 to 8 letters and digits) and can be
changed in the project settings. Changing it renames every issue of the project; notes
and repository issues that mention the old number keep it.

Click the copy icon next to the number on the issue page to copy it. The number works
in the [command palette](./finding-things), in the issues search (`DEMO-42`, `#42` or
`42`) and in every MCP tool that takes an issue.

## The chart

The issue page charts its occurrences over 1 hour, 24 hours (the default), 7 days or
30 days. Pick the period above the chart; it stays in the URL, so a link shows the same.

## Statuses

| Status      | Means                                                    |
| ----------- | -------------------------------------------------------- |
| Open        | Needs attention.                                         |
| In progress | Someone is working on it. Still counts as open.          |
| Resolved    | Fixed. A new occurrence re-opens it as a **regression**. |
| Ignored     | Does not matter, for good or until a condition is met.   |

When resolving or ignoring you can add a comment; it is saved as a note. An ignored
issue can come back:

- after a time (1 hour, 24 hours, 7 days),
- after 10, 100 or 1,000 more occurrences,
- when it escalates.

## Escalation

An open issue escalates when it suddenly happens far more often than usual: at least
10 occurrences in 5 minutes. The issue is flagged, its history records the burst and
the **Escalation** alert rule fires.

## Working on an issue

- **Assignee:** any member of the team.
- **Labels:** type a new name on the issue to create a label. Rename, recolor or
  delete labels in the project settings.
- **Notes:** free text for the team. Type `@` to tag a team member or an MCP agent.
  Agents write notes too, for example their triage result.
- **GitHub issue:** with a [linked repository](./projects#repository-integration),
  **Create GitHub issue** opens one with the stacktrace, environment, occurrence count
  and a link back.
- **History:** every status change, assignment, label change, regression and
  escalation, with who did it.

Select issues on the list to resolve, ignore or re-open several at once.
