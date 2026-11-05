# Issues

An issue collects every occurrence of one error, so you can see how often it happens,
who it hits and work on it with your team.

Every exception, browser error, failed job, failed or missed scheduled task, failed
command and error log line becomes an issue.

## Grouping

Occurrences of the same error are grouped under one issue:

| Source  | Grouped by                                                |
| ------- | --------------------------------------------------------- |
| Server  | The exception class or title, and the first in-app frame. |
| Browser | The error's title, its message and the top frame.         |
| Log     | The log channel and the message.                          |

IDs and numbers in messages are ignored when grouping, so `Order 4711 not found` and
`Order 4712 not found` are one issue. A fingerprint the SDK sends itself overrides all
of this.

Only log lines of level **error** and above become issues: error, critical, alert and
emergency. A line whose context carries an exception becomes a Server issue. Any other
line becomes a Log issue titled `<channel>: <message>`, cut to 200 characters.

## The issues list

![The issues list with three issues selected and the selection bar at the bottom](./screenshots/issues.png)

| Filter   | Choices                                                                               |
| -------- | ------------------------------------------------------------------------------------- |
| Status   | Open (the default, includes in progress), In progress, Resolved, Ignored, with counts |
| Source   | All, Server, Browser, Log                                                             |
| Release  | Up to 20 releases. Matches the release of the issue's latest occurrence               |
| Label    | One of the project's labels                                                           |
| Assigned | Everyone, Assigned to me, Unassigned                                                  |
| Watching | Only the issues you [watch](#watching)                                                |
| Search   | Message, class or [number](#issue-numbers)                                            |

Sort by **Last seen** (the default), **Most occurrences**, **Most users** or **First
seen**. The list shows 25 issues a page, each with its occurrences of the last 24 hours
as a small chart, its notes count, and flags: **New**, **Regression**, **Escalating**
and **Came back**.

## Issue numbers

Each issue gets a number made of the project's prefix and a counter, like `DEMO-42`.
The prefix is 2 to 8 letters and digits. You choose it when you create the project and
can change it in the project settings. Changing it renames every issue of the project.
Notes and repository issues that mention the old number keep it.

Click the copy icon next to the number on the issue page to copy it. The number works
in the [command palette](./finding-things#the-command-palette), in the issues search (`DEMO-42`, `#42` or
`42`) and in every MCP tool that takes an issue.

## The issue page

![An issue page with its occurrence chart, stacktrace and request context](./screenshots/issue.png)

The top shows the occurrences, users affected, first and last seen, the releases it
happened on and the hosts. Below the chart are four tabs:

- **Stacktrace**,
- **Occurrences**: each with its request, user, environment, release, host and a link
  to its trace,
- **Notes**,
- **History**.

With a tab focused, the left and right arrow keys move to the previous or next tab.

## The stacktrace

Each frame shows the lines around it, highlighted as PHP, JavaScript or TypeScript by
the file's extension, with the line that threw marked. Vendor frames are folded away
until you show them.

## Users affected

Each issue counts the signed-in users it happened to. A server SDK sends the signed-in
user with each error. The browser script takes the user from `data-user` or
`Lumberjack.setUser()`. The issue list can sort by users, the issue page shows the count
beside the occurrences, and alerts include it.

## Request and context

The latest occurrence's request, session and other context sit beside the stacktrace,
one table per object with its keys in alphabetical order. A table with more than 8
rows, such as the request's headers, starts folded. Click its title to open it.

## The chart

Pick the period above the chart:

| Period            | One bar per |
| ----------------- | ----------- |
| 1 hour            | 2 minutes   |
| 24 hours, default | hour        |
| 7 days            | 6 hours     |
| 30 days           | day         |

A link to the page keeps the period.

## Statuses

| Status      | Means                                                    |
| ----------- | -------------------------------------------------------- |
| Open        | Needs attention.                                         |
| In progress | Someone is working on it. Still counts as open.          |
| Resolved    | Fixed. A new occurrence re-opens it as a **regression**. |
| Ignored     | Does not matter, for good or until a condition is met.   |

When you resolve or ignore an issue you can add a comment. It is saved as a note, and
`@` tags work in it as in notes. Ignoring asks when the issue may come back:

- **When it escalates** (the default),
- in 1 hour, 24 hours or 7 days,
- after 10, 100 or 1,000 more occurrences,
- never: ignore it for good.

Ignoring several issues from the list always uses the default: they stay ignored until
they escalate.

When an ignored issue comes back it is open again and flagged **Came back**. Its
history says why: the time is over, it happened as many times again, or it escalated.
It counts as a regression and sends the **Regression** alert.

## Escalation

An open or in-progress issue escalates when it suddenly happens far more often than
usual: at least 10 occurrences in 5 minutes, and at least 5 times its usual rate for 5
minutes over the day before. The issue is flagged **Escalating** for an hour, its
history records the burst and the **Escalation** alert rule fires. An ignored issue
that was set to come back when it escalates comes back instead.

## Working on an issue

- **Assignee:** any member of the team. Whoever is assigned or taken off gets a mail,
  and a toast if Lumberjack is open, naming who did it: a person or an MCP token. Turn
  either off under [Notifications](./teams#notifications) in your account settings.
  You are not told about your own changes, so taking an issue yourself or starting work
  on it sends nothing. Resolving an issue unassigns it, and the history shows that. The
  assignee is told unless they resolved it themselves. Ignoring keeps the assignee. A
  regression comes back unassigned. A bulk action sends each person one message for all
  the issues it touched.
- **Labels:** type a new name on the issue to create a label. New labels take the next
  color. Rename, recolor or delete them in the project settings. The colors are gray,
  red, orange, yellow, green, teal, blue and purple.
- **Notes:** free text for the team. Type `@` to tag a team member or an MCP agent: a
  list of matching names opens, pick one with the arrow keys and `Enter` or `Tab`.
  `Ctrl+Enter` (`⌘+Enter` on a Mac) posts the note. Agents write notes too, for example
  their triage result.
- **GitHub issue:** with a [linked repository](./projects#repository-integration),
  **Create GitHub issue** opens one with the stacktrace, environment, occurrence count
  and a link back.
- **History:** when it was first seen, every status change with its note,
  assignment, label change, regression, escalation, return of an ignored issue and
  GitHub issue, with who did it.

![The Notes tab of an issue with a triage note an agent wrote over MCP](./screenshots/issue-notes.png)

Every member of the team can work on issues: change their status, assign them, label
them, write notes and create GitHub issues. **Start working** sets an issue in progress
and assigns you; **Stop working** sets it back to open.

Select up to 100 issues on the list to change them at once:

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

Watch an issue to hear what happens to it. **Watch** on the issue page starts,
**Stop watching** stops. The faces beside it show who watches it now. **Watching** on the issues
list shows only the issues you watch, and **Watch** in the selection bar watches the
selected ones. Agents can do the same with `watch_issue` and `unwatch_issue` over
[MCP](./mcp#tools), for the person the token belongs to.

You start watching an issue by yourself when you are assigned to it, write a note on
it, are tagged in a note on it, or resolve or ignore it. Once you stop watching an
issue, only **Watch** makes you watch it again.

What watchers hear about other people's actions:

- **A regression or an escalation:** a mail right away, like the alert.
- **Notes, status changes and assignee changes:** grouped. Status changes are
  resolved, ignored, re-opened and in progress. The first update starts a 5 minute
  window. At its end one mail lists everything that happened in it: who did what and
  when, with the notes and comments quoted. A single update gets its own subject, such
  as "Sofie wrote a note on DEMO-12".

Each also shows a toast if Lumberjack is open, and a browser notification where you
turned those on. Under [Notifications](./teams#notifications) in your account settings,
choose mail, in app, browser or any of them for issues you watch, mentions and
assignments.

Every watching mail has a **Stop watching** link that works without signing in. Mail
clients that support one-click unsubscribe offer it too.

### One mail per thing that happened

You get at most one mail about one thing that happened, however many reasons you have
to hear of it. A mention or an assignment comes first, then watching, then an alert
rule's mail channel. The footer of that mail lists every reason, such as "you watch
DEMO-12 and the Regression rule of Ops Team sends to the mail channel Ops". When the
alert summary of a bulk resolve or ignore reaches you by mail, it replaces your
watching mail. Chat channels still get their own message.

## Limits

| What                    | Limit             |
| ----------------------- | ----------------- |
| Issues per list page    | 25                |
| Issues selected at once | 100               |
| Note length             | 20,000 characters |
| Label name              | 32 characters     |
| Labels per issue        | 10                |
| Project prefix          | 2 to 8 characters |
