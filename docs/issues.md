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

## The stacktrace

Each frame shows the lines around it, highlighted as PHP, JavaScript or TypeScript by
the file's extension, with the line that threw marked. Vendor frames are folded away
until you show them.

## Users affected

Each issue counts the signed-in users it happened to, from the user id the SDK sends
with each error (the Laravel SDK takes it from `auth()`, the browser script from
`data-user` or `Lumberjack.setUser()`). The issue list can sort by it, the issue page
shows it beside the occurrences, and alerts include it. One user hitting an error 400
times and 400 users hitting it once are different problems.

## Request and context

The latest occurrence's request, session and other context sit beside the stacktrace,
one table per object with its keys in alphabetical order. A table with more than 8
rows, such as the request's headers, starts folded; click its title to open it.

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

- **Assignee:** any member of the team. Whoever is assigned, or taken off, gets a mail
  and a toast if Lumberjack is open (each can be turned off under
  [Notifications](#watching) in your profile), naming who did it (a person, or an MCP token).
  Nobody hears about their own change, so taking an issue yourself, or starting work
  on it (which assigns you), sends nothing. Resolving an issue takes it off its
  assignee, in the history like any unassign; the assignee is told unless they resolved
  it themselves. Ignoring keeps the assignee, and a regression comes back unassigned.
  A bulk action sends each person one message for all the issues it touched.
- **Labels:** type a new name on the issue to create a label. Rename, recolor or
  delete labels in the project settings.
- **Notes:** free text for the team. Type `@` to tag a team member or an MCP agent.
  Agents write notes too, for example their triage result.
- **GitHub issue:** with a [linked repository](./projects#repository-integration),
  **Create GitHub issue** opens one with the stacktrace, environment, occurrence count
  and a link back.
- **History:** every status change, assignment, label change, regression and
  escalation, with who did it.

Select issues on the list to change several at once:

- **Resolve**, **Ignore** or **Re-open** them, after a confirmation. Resolving or
  ignoring sends one [status change alert](./alerts#resolved-or-ignored) for all of them.
- **Assign** them to a member, to yourself (**Me**) or to nobody (**Unassign**).
- **Label** them: add one of the project's labels, or remove it.
- **Start working** on them: they go in progress, and the unassigned ones are assigned
  to you.
- **Watch** them, see [Watching](#watching).

Assigning, labeling and starting work apply at once and confirm with a toast. Each
person assigned or taken off gets one message for the whole action.

## Watching

Watch an issue to hear what happens to it. **Watch** on the issue page starts, **Stop
watching** stops; the faces beside it are who watches it now. **Watching** on the issues
list shows only the issues you watch, and **Watch** in the selection bar watches the
selected ones. Agents can do the same with `watch_issue` and `unwatch_issue` over
[MCP](./mcp#tools), for the person the token belongs to.

You start watching an issue by yourself when you are assigned to it, write a note on
it, are tagged in a note on it, or resolve or ignore it. Stopping sticks: after you stop
watching an issue, acting on it does not make you watch it again. Only **Watch** does.

What watchers hear, never about their own actions:

- **A regression or an escalation:** a mail right away, like the alert.
- **Notes, status changes (resolved, ignored, re-opened, in progress) and assignee
  changes:** grouped. The first one starts a 5 minute window, and at its end one mail
  lists everything that happened in it, with who did what and when and the notes and
  comments quoted. A single update is mailed on its own subject, such as "Sofie wrote
  a note on DEMO-12".

Each also shows a toast if Lumberjack is open. Under **Notifications** in your profile,
choose mail, toast or both for issues you watch, and the same for mentions and
assignments. Everything is on until you turn it off.

Every watching mail has a **Stop watching** link that works without signing in, and mail
clients that support one-click unsubscribe offer it too.

### One mail per thing that happened

You get at most one mail about one thing that happened, however many reasons you have
to hear of it. A mention or an assignment comes first, then watching, then an alert
rule's mail channel; the one mail you get says every reason in its footer ("you watch
DEMO-12 and the Regression rule of Ops Team sends to the mail channel Ops"). Addresses
are compared without case and surrounding spaces. The summary of a bulk resolve or
ignore names more issues than anyone watches, so there it stands in for the watching
mail. Chat channels are not affected.
