# Teams and accounts

Everything in Lumberjack belongs to a team: its projects, its alert channels and rules,
its API and MCP tokens. People see the teams they are members of; system administrators see
every team (see [Setup and system administration](./administration)).

## Roles

| Role   | Can                                                                                                                                                                                   |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Owner  | Everything an admin can, and transfer ownership. One per team.                                                                                                                        |
| Admin  | Create projects, change their settings, manage members, invitations, API and MCP tokens, alerts and retention. Pair TVs, manage displays, share screens by link and reload a display. |
| Member | See every project of the team and work on its issues: status, assignee, labels, notes, watching. See, design and delete status screens, and reload the TVs showing one.               |

## The team page

**Team** in the sidebar shows the team, on its **Members and tokens** tab.

![The team page with its members, an invitation form and the MCP tokens](./screenshots/team.png)

- **Members:** change a member's role, or **Remove** them. The owner cannot be removed
  or demoted; **Transfer ownership** (owner only) makes another member the owner, and
  the old owner an admin.
- **Invite by email:** as Admin or Member. Open invitations show when they expire
  (after 7 days), and can be sent again or revoked.
- **API and MCP tokens:** see [MCP](./mcp#tokens) and the [metrics API](./metrics-api#authentication).
- **Retention:** the Team entry's second tab: the team's [retention](./projects#retention),
  read only for members.

Anyone can **Create a team**: it starts empty, with them as the owner. The team switcher
at the top of the sidebar moves between teams. Each browser tab keeps its own team and
project: switching in one tab leaves the others as they are, also when they are
reloaded. A new tab starts with the team picked last.

## Invitations

An invitation link opens a page to join the team:

- without an account, choose a name and a password, then **Create account and join**,
- with an account, **Log in to accept**,
- signed in as someone else, sign out first.

An expired or revoked link says **Invitation expired**; ask for a new one.

## First login

The first time you sign in (after a required password change and a confirmed email
address), Lumberjack opens a short onboarding of three steps, each with **Skip**:

1. **Welcome:** your teams with their projects, and where things are: the sidebar, the
   command palette (Ctrl+K, or Cmd+K on a Mac) and the notification bell at the top of
   the sidebar. Without a team it says how to create one or be invited.
2. **Notifications:** pick **Quiet** (what concerns you, in app only), **Balanced** (the
   defaults below) or **Everything** (every kind in app and in the browser, mail as
   Balanced), change any cell, then **Next** saves it as your
   [notification settings](#notifications).
3. **Browser on this device:** **Enable on this device** turns on
   [browser notifications](#browser-notifications) here; **Not now** skips it.

**Done** or **Skip** ends it for good and continues to the page you were going to, or
the projects. **Show onboarding again** at the bottom of Notifications in your account
settings opens it again. Users who existed before it was added do not see it.

## Your account

**Profile and settings** under your name at the bottom of the sidebar.

- **Profile:** your name and email (a new email has to be confirmed again).
- **Notifications:** what you hear about and how, and the browsers you get
  notifications in; see [Notifications](#notifications).
- **Security:** asks for your password first (again after 3 hours). Change your
  password, and turn on **two-factor authentication**: scan the QR code with an
  authenticator app and confirm with a code. Keep the recovery codes; each signs you in
  once when the app is not at hand. **Regenerate codes** replaces them, **Disable 2FA**
  turns it off.
- **Appearance:** light, dark or the system's.

![The overview in the light appearance](./screenshots/overview-light.png)

**Delete account** (on the profile, with your password) also deletes the teams where
you are the only member, with their projects. It is refused for the last active
administrator, and for the owner of a team with other members until ownership is
transferred.

In production a password needs at least 12 characters, upper and lower case, a number
and a symbol, and must not appear in a known data breach (checked with Have I Been
Pwned, so the server needs outbound HTTPS).

Signing in, two-factor codes and password changes are rate limited.

## Notifications

**Notifications** in your account settings (under your name at the bottom of the
sidebar, or **Settings** in the bell's list) sets, for each kind, how you hear of it:
**Mail**, **In app** (kept in the [notification center](#notification-center) and shown
as a toast while Lumberjack is open) or **Browser** (a notification from your browser,
also while Lumberjack is closed). Alerts a rule sends to a team channel are separate and
set up under the team's [alerts](./alerts).

**About you:**

| Row              | When                                                         | Mail | In app | Browser |
| ---------------- | ------------------------------------------------------------ | ---- | ------ | ------- |
| Issues you watch | Something happens to an issue you [watch](./issues#watching) | on   | on     | off     |
| Mentions         | A note tags you                                              | on   | on     | on      |
| Assignments      | Someone assigns you to issues or takes you off them          | on   | on     | on      |

**Your projects**, every project of your teams you can view:

| Row             | When                                                                                                                                                                                 | Mail | In app | Browser |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---- | ------ | ------- |
| New issues      | A new error, a regression or an escalation, at the moments the team's alert rule of that type sends (its environments, its throttle), with or without channels; never browser errors | off  | on     | off     |
| Health checks   | A [health check](./health) turns degraded or down, and when it is ok again. Muted checks stay quiet                                                                                  | off  | on     | off     |
| Queue backlogs  | A queue stays over the queue backlog rule's thresholds, and when it is back under. Muted queues stay quiet                                                                           | off  | on     | off     |
| Scheduled tasks | A scheduled task fails or is missed. Such an issue is told here instead of under New issues, never under both                                                                        | off  | on     | off     |
| Agents          | An agent has sent no heartbeat for 5 minutes; once, until it is back. Several at once are one notification per project                                                               | off  | on     | off     |
| Releases        | A project's server reports a release for the first time (the first release a project ever reports is not news)                                                                       | off  | on     | off     |

The columns show the defaults, used until you save your own choice. The presets above
the table, **Quiet**, **Balanced** and **Everything**, fill it in one click, as in the
[first login](#first-login). Mail about issues
you watch is the grouped mail described under [watching](./issues#watching). A
project mail is short: what happened, and a button to the page. If your address is also
a team mail channel, you get both.

### Notification center

The bell at the top of the sidebar shows how many notifications you have not read
(up to 99+), live. It opens the latest 15: what happened, the project (and the team when
you are in several), how long ago, and a dot while unread. Opening one marks it read and
takes you there, switching the tab to that project's team if needed. **Mark all as read**
clears the count; **See all** opens the full history, also in the command palette as
Notifications, filtered by unread or by kind, each with **Mark as read** or **Mark as
unread**. Everything you get in app is kept there, from every team, for 90 days (see
[retention](./projects#retention)).

### Browser notifications

Browser notifications come per browser: open Notifications in your account settings in
each browser (or phone) you want them in and click **Enable on this device**, then allow
notifications when the browser asks. The list below shows every browser they are on in,
with **Remove**. A click on a notification focuses an open Lumberjack tab on the page,
or opens one. While a Lumberjack tab is in front, it shows the toast instead.

They need HTTPS (or `localhost`). A browser that cannot show them, or a site whose
notifications are blocked in the browser's settings, says so instead. On an iPhone or
iPad, add Lumberjack to the home screen first. The server needs no setup: it makes its
keys on first use (see [Docker](./docker#settings)).

## While Lumberjack is open

New issues, regressions and escalations show as a toast with **View** to open it, with
a short chime, as chosen for **New issues** and **Scheduled tasks** under Notifications.
**Sound for new errors** in the sidebar turns the chime off or on for this browser.
Mentions, assignments and updates on issues you watch, and everything else you get in
app, show as toasts too.
