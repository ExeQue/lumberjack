# GitHub

Connect a project to a repository on [GitHub](https://github.com), and Lumberjack shows its
pull requests, workflow runs and releases on a page of its own and offers the figures to
screen [widgets](../widgets.md) as [data sources](../data-sources.md).

Lumberjack only reads GitHub, through its [REST API](https://docs.github.com/rest). This is
apart from the [repository integration](../projects.md#repository-integration), which creates issues on GitHub and
has a token of its own.

## Connecting

Team owners and admins connect GitHub from **Data sources**, under **Connectors**, or from
the **GitHub** page of a project that has none yet.

1. On GitHub, open **Settings**, **Developer settings**, **Personal access tokens**,
   **Fine-grained tokens**, and generate a token whose **Repository access** includes the
   repository, with these repository permissions, each **Read-only**:

    | Permission        | What it reads                                    |
    | ----------------- | ------------------------------------------------ |
    | **Metadata**      | The repository, its default branch, open issues. |
    | **Pull requests** | Pull requests, and counting them.                |
    | **Actions**       | Workflow runs.                                   |
    | **Contents**      | Releases and tags.                               |

    Give it no write permission. A token for an organization's repository may need the
    organization's approval first.

2. In Lumberjack, choose **Connect** and give the **Repository** as `owner/name`, such as
   `acme/shop`, or its address on github.com, and the **Token**.
3. Choose **Connect**. Lumberjack asks GitHub once for each permission, and says on the token
   which one it lacks.

The token is stored encrypted, never shown again, and only ever sent to `api.github.com`.

A project reads as many repositories as you connect, one connection each. Connecting a
repository the project already reads renews its token. With more than one, a picker beside
the title switches between them. Under the page's **Connection** tab, for team owners and
admins:

- **Connect another repository** opens the connect dialog.
- **Disconnect** removes this connection and its data sources.

## The GitHub page

Once connected, **GitHub** shows in the project's sidebar. On top are the open pull
requests, those waiting for review, the pull requests merged and the workflow runs that
failed in the period, each against the period before, the open issues, the result of the
latest run on the default branch and the latest release.

| Tab               | What it shows                                                    |
| ----------------- | ---------------------------------------------------------------- |
| **Pull requests** | The newest open pull requests, drafts marked, with their author. |
| **Actions**       | The latest workflow runs on every branch, with their result.     |
| **Releases**      | The newest releases, pre-releases marked. Drafts are not shown.  |
| **Connection**    | The connection, for team owners and admins.                      |

The figures on top are kept as long as on the other statistics pages (5 minutes for 7 days),
and the tabs' lists for a minute, so a reloaded page does not ask GitHub again.

## Data sources

A GitHub connection offers these sources, filed under **GitHub** and the repository in the
designer's **Data source** tree. They are read from GitHub only while a screen shows them,
like an app's [connected sources](../data-sources.md#connected-sources).

| Source                           | Shape  | What it is                                                                |
| -------------------------------- | ------ | ------------------------------------------------------------------------- |
| Open pull requests               | number | Open pull requests, drafts included, as they are now.                     |
| Pull requests waiting for review | number | Open pull requests that are not drafts and have no review yet.            |
| Pull requests merged             | number | Pull requests merged in the period.                                       |
| Failed workflow runs             | number | Workflow runs started in the period that failed.                          |
| Open issues                      | number | Open issues, pull requests not counted, as they are now.                  |
| CI on the default branch         | list   | One row: the latest workflow run on the default branch, and its result.   |
| Latest release                   | list   | One row: the newest published release and its day, or else the first tag. |

"Waiting for review" is GitHub's search `is:pr is:open draft:false review:none`. Search
cannot ask for "review requested or not reviewed yet", so a pull request reviewed once and
waiting for another round is not counted.

Counts are read from GitHub's search API, which allows 30 calls a minute per user. A screen
asks each source at most every 5 minutes, and the page keeps its answers a while, so that is
rarely reached; when it is, the source says so and is asked again shortly.

## When GitHub cannot be read

The connect dialog, the connection and the GitHub page say why:

| Error                                     | What to do                                                            |
| ----------------------------------------- | --------------------------------------------------------------------- |
| GitHub refused the token                  | The token expired or was revoked. Generate another and connect again. |
| The token cannot read the repository      | Check the name, and that the token's repository access includes it.   |
| The token cannot read the repository's... | Give the token the permission named, read-only.                       |
| GitHub asks to slow down                  | A rate limit was reached. It is asked again shortly.                  |
| The GitHub token cannot be decrypted      | `APP_KEY` changed. Connect GitHub again.                              |
