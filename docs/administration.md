# Setup and system administration

## First-run setup

A Lumberjack without any users sends every page to `/setup`. There you choose the
name, email and password of the first system administrator. There is no default
account and no default password. The administrator starts without a team and
lands on **Administration > All teams**, where the teams are created.

### Protect setup with a token

Between starting Lumberjack and completing setup, whoever opens it first becomes
its administrator. On anything reachable from outside, set a setup token:

```dotenv
LUMBERJACK_SETUP_TOKEN=a-long-random-string
```

Setup then asks for the token as well. Generate one with, for example,
`openssl rand -hex 24`.

### The setup flag

Once the first user exists, Lumberjack writes the file:

```
storage/app/setup/complete
```

From then on no request asks the database whether setup is done. If the file is
missing but users exist, Lumberjack checks the database once and writes the file
again, so a lost file heals itself.

To have Lumberjack check its setup again, delete the file, either through the
storage mount or with:

```bash
php artisan setup:clear
```

`php artisan migrate:fresh` deletes the flag too.

## What `/setup` does on a running Lumberjack

| State                              | `/setup`                                                         |
| ---------------------------------- | ---------------------------------------------------------------- |
| No users                           | Setup form (asks for the token when one is set)                  |
| Users, but no active administrator | Recovery: open only with `LUMBERJACK_SETUP_TOKEN`; otherwise 404 |
| An active administrator exists     | 404, as if the page did not exist                                |

An administrator is active when they are not suspended.

Recovery is never open without the token. Otherwise anyone who found `/setup` on
a Lumberjack that lost its last administrator could make themselves one and see
every team. When the flag is set and no token is configured, `/setup` answers 404
from the flag alone, without a database query, and both `GET` and `POST` are rate
limited, so requests to it cost no more than any other 404.

### Lost the last administrator?

From a shell on the server (or `docker compose exec lumberjack ...`):

```bash
php artisan lumberjack:make-admin someone@example.com
```

Or set `LUMBERJACK_SETUP_TOKEN` and create a new administrator through `/setup`.

Lumberjack guards against getting there: the last active administrator cannot
take administration away from themselves, be suspended, or delete their own
account.

## Command line

| Command                                              | What it does                                                                                                                                                                                                                                                                      |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `php artisan lumberjack:create-user --admin`         | Creates a user (`--name`, `--email` and `--password`, asked for when left out); `--admin` makes them an administrator (the first user created always is one), `--team="Name"` makes them the owner of a new team. Otherwise they are in no team until invited.                    |
| `php artisan lumberjack:make-admin {email}`          | Makes an existing user an administrator.                                                                                                                                                                                                                                          |
| `php artisan lumberjack:make-admin {email} --revoke` | Takes administration away, unless they are the last active administrator.                                                                                                                                                                                                         |
| `php artisan setup:clear`                            | Deletes the setup flag.                                                                                                                                                                                                                                                           |
| `php artisan lumberjack:prune`                       | Deletes data older than its [retention](./projects#retention). Runs by itself every day.                                                                                                                                                                                          |
| `php artisan webpush:vapid --show`                   | Prints a new VAPID key pair for [browser notifications](./teams#browser-notifications), to set as `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`. Not needed: without them a pair is made on first use and stored encrypted. New keys make every browser turn notifications on again. |

The scheduler runs the rest by itself; there is no need to call them:

| Every     | Command                                                                                                                                                                                                                                  |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Minute    | `lumberjack:mark-stuck-jobs`, `lumberjack:mark-missed-tasks`, `lumberjack:return-ignored-issues`, `lumberjack:send-watch-digests`, `lumberjack:check-queue-backlogs`, `lumberjack:run-health-checks`, `lumberjack:notify-offline-agents` |
| 5 minutes | `horizon:snapshot`                                                                                                                                                                                                                       |
| Hour      | `model:prune`: agents and health check hosts not seen for 24 hours                                                                                                                                                                       |
| Day       | `lumberjack:archive-silent-health-checks`, and `lumberjack:prune` at 03:15 Europe/Copenhagen                                                                                                                                             |

## Mail

Lumberjack sends these mails, all in one layout with a plain-text version:

| Mail                                    | Sent to                                                  | From          |
| --------------------------------------- | -------------------------------------------------------- | ------------- |
| New error, regression, escalation       | the rule's mail channels, and watchers                   | alerts        |
| Note, resolved or ignored, bulk resolve | the rule's mail channels                                 | alerts        |
| Health check down, degraded, recovered  | the rule's mail channels                                 | alerts        |
| Queue backlog, queue recovered          | the rule's mail channels                                 | alerts        |
| Test message                            | the channel being tested                                 | alerts        |
| Mention                                 | the member a note tags                                   | notifications |
| Assigned, taken off                     | the member concerned, once per action                    | notifications |
| Updates on a watched issue              | its [watchers](./issues#watching)                        | notifications |
| New issues, health checks and the rest  | members who turned on that [mail](./teams#notifications) | notifications |
| Invitation                              | the invited address                                      | notifications |
| Password reset, email confirmation      | the account                                              | notifications |

Subjects start with the project in brackets (`[Demo] New error: DEMO-12 RuntimeException`),
so a mail filter can sort them per project. Each mail says in its footer why it was sent
and where to change that. An address gets [one mail per thing that
happened](./issues#one-mail-per-thing-that-happened), however many rules and reasons lead
to it.

| Variable                          | Default             | What it does                                    |
| --------------------------------- | ------------------- | ----------------------------------------------- |
| `MAIL_ALERTS_FROM_ADDRESS`        | `MAIL_FROM_ADDRESS` | Sender of what alert rules send.                |
| `MAIL_NOTIFICATIONS_FROM_ADDRESS` | `MAIL_FROM_ADDRESS` | Sender of personal mail: everything else above. |

Both are sent from the name "Lumberjack" (`MAIL_ALERTS_FROM_NAME`,
`MAIL_NOTIFICATIONS_FROM_NAME` change it).

Mails follow the reader's light or dark mode where the mail client supports it
(Apple Mail, iOS, Outlook for Mac, Thunderbird; Outlook.com partly). Clients that invert
colors by themselves (the Gmail app, Outlook for Windows) get solid buttons and a logo on
its own background, which survive that. Fonts fall back to the system's when IBM Plex
Sans and JetBrains Mono are not installed; no web fonts are loaded.

### Previewing mails

In local development (`APP_ENV=local`), signed in, `/dev/mail` lists every mail with
sample data; each one shows light and dark side by side and the plain text. Nothing is
sent and nothing is saved. The route does not exist in any other environment.

## Administration pages

Administrators see an **Administration** section in the sidebar, folded away until
opened (and open on its pages). Everything they
do there is written to the audit log.

- **All teams:** every team with its owner, members, projects and errors of the
  last 24 hours. Open a team to work in it, delete it with all its data, or create
  one. The owner can be an existing user, or the email of someone without an
  account: the team then waits without members, and that person is invited to own
  it.
- **All users:** 50 a page, **Search by name or email**, with badges for
  **Administrator**, **Suspended** and **Must change password**. Invite someone to a
  team as admin or member, send a password reset link, require a new password, make or
  revoke administrators, suspend or reinstate. Administrators cannot do any of that to
  their own account.
- **Audit log:** who did what, and when, 50 a page: teams created, deleted or opened,
  users invited, suspended or reinstated, administration granted or revoked, password
  resets sent and new passwords required.
- **Retention:** how long this Lumberjack keeps each kind of data, as the default
  and the maximum for every team. See [Retention](./projects#retention).
- **Horizon:** the queue dashboard (`/horizon`), linked from the sidebar and the
  command palette. For system administrators only, in every environment including
  local; to anyone else it answers 404.

![Administration > Retention: the default and maximum for every kind of data](./screenshots/admin-retention.png)

Administrators can see every team and project, whether or not they are members.

### Accounts come by invitation

There are no personal teams: someone belongs to the teams they were invited to, and an
account in no team sees a page saying so until an invitation arrives.

There is no public registration. Accounts come from first-run setup, an
invitation, or the command line. Teams, invitations and your own account are on
[Teams and accounts](./teams).

### Who may create teams

By default anyone signed in can create a team on the team page and becomes its owner.
Set `LUMBERJACK_TEAM_CREATION=admins` to leave that to system administrators; they also
create teams for others under **All teams**.

### Suspending a user

A suspended user is signed out at once (database sessions end immediately, others
on their next request), cannot sign in, and their MCP tokens stop working. Signing in
answers as it does for a wrong password, so the page never confirms a suspended account's
password; a session that was already open is told the account is suspended.

### Requiring a new password

A user whose password an administrator requires to be changed can only reach the
security settings until they choose a new one.

## About

**About** in the menu under your name (it shows the version, e.g. "Lumberjack 0.2.0")
opens a page with the version, the commit it was built from, links to the release notes
and the documentation. System administrators also see what it runs on: PHP, Laravel, the
database, and the queue, cache and session drivers, handy in a bug report.

The Docker image knows its version from its build (`LUMBERJACK_VERSION` and
`LUMBERJACK_REVISION`, set from the release tag and commit). A checkout asks git instead
(`git describe --tags`, checked every five minutes), and says "dev" without git.

## Security

### Response headers

Every response carries `X-Frame-Options: SAMEORIGIN` and
`Content-Security-Policy: frame-ancestors 'self'` (no other site can frame Lumberjack),
`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` (an
invitation link's token never leaves in a Referer) and a `Permissions-Policy` that turns
off camera, microphone, location, payment and USB. With an `https://` `APP_URL` it also
sends `Strict-Transport-Security: max-age=31536000`. Browser ingest and the SDK script
still work from other origins.

### Proxies and links

Links in mails, alerts and notifications are always built from `APP_URL`, never from the
`Host` or `X-Forwarded-Host` of a request. `TRUSTED_PROXIES` lists the proxies whose
`X-Forwarded-*` headers are believed; see [Docker](./docker#reverse-proxy).

### Secrets and size of stored context

Exception, log, job and command context is scrubbed on the server before it is stored,
by key (see [Extra secret fields](./projects)) and by value: bearer and basic
credentials, `token=`, `password=`, `signature=`, `key=` and similar parameters in URLs
and query strings, passwords in `scheme://user:password@host`, JWTs, AWS access key ids,
Stripe keys and GitHub and Slack tokens become `[filtered]`. Request URLs are scrubbed
the same way. Add patterns of your own in `config/lumberjack.php` under
`scrubbing.value_patterns` (regular expression => replacement).

One context keeps at most 64 KB of JSON: top-level entries that no longer fit are
replaced by `[truncated] N KB`.

### Ingest and MCP limits

- Browser ingest checks the public key and origin before it reads the body, and refuses
  a compressed body (`415`). It takes 30 batches a minute per address and key, and 300
  a minute per address whatever the key.
- An address that sends 30 unknown server tokens (`/api/ingest`) or unusable MCP tokens
  (`/mcp`) within a minute gets `429` without a lookup until the minute is over.
