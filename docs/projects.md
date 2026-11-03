# Projects

A project is one app. It belongs to a team; every member of the team can see it, and
owners and admins can change its settings.

## Creating a project

Give it a name and an issue prefix. The prefix is suggested from the name (initials
for several words, the word itself for one) and is used for
[issue numbers](./issues#issue-numbers).

The project settings then show how to install the package, the `.env` values and the
agent setup.

## Switching project

Pick another project in the sidebar or the [command palette](./finding-things) and you
land on the same page in it: from the issues to its issues, from a job to its jobs.
Pages that belong to one thing, like a trace, open the other project's overview.

## Settings

- **Keys:** the server token (for the agent) and the public key (for browser errors).
  Both can be rotated; the server token is shown only once.
- **Allowed origins:** browser errors are only accepted from these origins.
- **Extra secret fields:** masked before storage in headers, session, Context and log
  context. A plain word masks every key containing it (`cpr`), `*` is a wildcard on the
  key name (`card_*`), and a dotted pattern matches the full path
  (`session.checkout.*`).
- **Agents:** each server's agent checks in every minute; one that stops is shown as
  offline.
- **Alerts:** the project's [rules and own channels](./alerts).
- **Labels:** rename, recolor or delete [issue labels](./issues#working-on-an-issue).
- **Retention:** see below.
- **Automatic triage:** see [MCP](./mcp#automatic-triage).

## Retention

Data is kept for a number of days per category. Empty means inherit; the most specific
value wins: team, project, job class or log channel.

| Category    | Default |
| ----------- | ------- |
| Errors      | 90 days |
| Jobs        | 30 days |
| Logs        | 14 days |
| Performance | 14 days |

Failed job runs always follow error retention.

## Repository integration

Link a GitHub repository to create GitHub issues from Lumberjack issues, by button or
through MCP. It needs a fine-grained token with only **Issues: read and write** on that
repository; **Create token on GitHub** opens GitHub with the right settings filled in.
Lumberjack warns before the token expires. Creating issues in a public repository has
to be allowed explicitly.

## Deleting a project

The danger zone deletes the project and all its data. Raw data is removed in the
background in batches and can take several minutes.
