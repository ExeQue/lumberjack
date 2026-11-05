# Let an AI agent read your errors

Lumberjack is an MCP server. Connect Claude Code, Cursor or another MCP client, and
the agent can read your issues, traces, logs and performance data, and help fix them.

## 1. Create a token

You need to be a team owner or admin.

1. Open **Team** in the sidebar. **API and MCP tokens** is on the **Members and tokens**
   tab.
2. Under **New token**, fill in:
    - **Name**: shown on every note and change the agent makes, such as "Claude Code".
    - **Projects**: the projects the agent may see.
    - **Scope**: **read** only reads. **write** can also change issues: status,
      assignee, labels, notes and GitHub issues.
    - **Expires**: when the token stops working.
3. Click **Create token** and copy it. It starts with `lj_mcp_` and is shown once.

Start with **read**. Give **write** only to an agent you want to triage issues.

![The team page with the tokens](../screenshots/team.png)

A token acts as the person who created it and stops working when that person is no
longer an owner or admin of the team.

## 2. Connect your client

The server is at `https://your-lumberjack/mcp`, over streamable HTTP, with the token as
a bearer token.

**Claude Code:**

```bash
claude mcp add --transport http lumberjack https://your-lumberjack/mcp \
  --header "Authorization: Bearer lj_mcp_..."
```

**Cursor**, in `.cursor/mcp.json`:

```json
{
    "mcpServers": {
        "lumberjack": {
            "url": "https://your-lumberjack/mcp",
            "headers": { "Authorization": "Bearer lj_mcp_..." }
        }
    }
}
```

Other clients take the same URL and header.

**When it works:** ask the agent "Which Lumberjack projects can you see?". It answers
with your projects, and the token's **Last used** on the team page updates.

## 3. Ask it things

Name the project by its slug, or let the agent look it up.

- "What are the newest open issues in `shop`, and which one affects the most users?"
- "Show me issue SHOP-42 and find the cause in this repository."
- "Why is `POST /checkout` slow? Look at its slowest requests and their queries."
- "Which queries in `shop` are flagged as N+1, and where in the code do they come from?"
- "Which jobs failed in the last 24 hours, and why?"
- "Are any health checks down or degraded?"
- "Search the logs for `payment declined` around trace 4f2a...."
- "What's in my triage inbox?"

With a **write** token:

- "Mark SHOP-42 as in progress, assign it to me and add a note with what you found."
- "Resolve SHOP-42 with a note that the fix is in the last release."
- "Create a GitHub issue for SHOP-42."

Notes and status changes the agent makes show in Lumberjack under the token's name.

## 4. Triage automatically

A project's **Settings** has an **Automatic triage** section with a GitHub Actions
workflow. It runs Claude Code in your repository every 15 minutes, triages new and
regressed issues through MCP, and answers notes that tag it. It can open a draft pull
request for a small fix. It never resolves an issue or merges.

![The Automatic triage section](../screenshots/settings-triage.png)

The settings show each step: a **write** token, two repository secrets and the
workflow file. GitHub has to reach your Lumberjack, so it must be on a public URL.

## Limits

| What                 | Limit                     |
| -------------------- | ------------------------- |
| Calls per token      | 120 a minute              |
| Token name           | 100 characters            |
| Token expiry         | 30, 90 or 365 days, or never |

## Reference

- [MCP](../mcp.md): tokens, every tool, and automatic triage.
- [Issues](../issues.md): statuses, notes and watching.
