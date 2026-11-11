# GitHub

Connect a project to a repository on [GitHub](https://github.com), and Lumberjack shows its
pull requests, commits, workflow runs, releases, and, with the optional permissions, its
issues and security alerts on a page of its own and offers the figures to
screen [widgets](../widgets.md) as [data sources](../data-sources.md).

Lumberjack only reads GitHub, through its [REST API](https://docs.github.com/rest). This is
apart from the [repository integration](../projects.md#repository-integration), which creates issues on GitHub and
has a token of its own.

## Connecting

Team owners and admins connect GitHub from **Data sources**, under **Connectors**, or from
the **GitHub** page of a project that has none yet.

1. In Lumberjack, choose **Connect** and give the **Repository** as `owner/name`, such as
   `acme/shop`, or any address on github.com under it, such as
   `https://github.com/acme/shop/pull/12`.
2. **Create token on GitHub** opens GitHub with the name, owner, expiry (90 days) and the
   permissions below filled in, the optional ones included. Under **Repository access**,
   choose **Only select repositories** and pick the repository; GitHub has no way to fill
   that in. The token needs these repository permissions, each **Read-only**:

    | Permission                 | Needed   | What it reads                                           |
    | -------------------------- | -------- | ------------------------------------------------------- |
    | **Metadata**               | Required | The repository, its default branch, open issues, stars. |
    | **Pull requests**          | Required | Pull requests, counting them and their lead time.       |
    | **Actions**                | Required | Workflow runs.                                          |
    | **Contents**               | Required | Releases, tags and commits.                             |
    | **Issues**                 | Optional | Issues opened and closed, and open bugs.                |
    | **Dependabot alerts**      | Optional | Open Dependabot alerts, by severity.                    |
    | **Code scanning alerts**   | Optional | Open code scanning alerts.                              |
    | **Secret scanning alerts** | Optional | Open secret scanning alerts.                            |

    Give it no write permission. A token for an organization's repository may need the
    organization's approval first.

3. Paste the token under **Token** and choose **Connect**. Lumberjack asks GitHub once for
   each required permission, and says on the token which one it lacks. It then asks for
   each optional one and remembers which the token has: their sources are offered only
   then, and the message after connecting names the ones missing. A security feature the
   repository has turned off (GitHub answers it with a 403 or 404 that does not say the
   token is refused) counts as had; its figure then says it is off.

The optional permissions are asked again whenever the repository is connected again, so to
add one, give the token the permission (or create a new one) and connect the repository
again. Connections made before optional permissions existed have none until then.

The token is stored encrypted, never shown again, and only ever sent to `api.github.com`.

A project reads as many repositories as you connect, one connection each. Connecting a
repository the project already reads renews its token. With more than one, a picker beside
the title switches between them. Under the page's **Connection** tab, for team owners and
admins:

- **Connect another repository** opens the connect dialog.
- **Disconnect** removes this connection and its data sources.
- **Optional permissions** lists each, whether the token had it when last connected, and
  what it adds.

## The GitHub page

Once connected, **GitHub** shows in the project's sidebar. On top are the open pull
requests, those waiting for review, the pull requests merged, their median lead time, the
commits on the default branch and the workflow runs that failed in the period, each against
the period before, the commit authors, the open issues, the result of the latest run on the
default branch, the latest release, and the stars with forks and watchers. With **Issues**,
the issues opened and closed in the period (against the period before) and the open bugs
are there too.

| Tab               | What it shows                                                                                   |
| ----------------- | ----------------------------------------------------------------------------------------------- |
| **Pull requests** | The newest open pull requests, drafts marked, with their author.                                |
| **Commits**       | Commits on the default branch over the period as a chart, the top contributors, the latest.     |
| **Actions**       | The latest workflow runs on every branch, with their result.                                    |
| **Releases**      | The newest releases, pre-releases marked, each opening to its notes. Drafts are not shown.      |
| **Security**      | Open Dependabot (by severity), code scanning and secret scanning alerts, or why there are none. |
| **Connection**    | The connection and its optional permissions, for team owners and admins.                        |

Each list shows its first 20 rows (the top contributors 10). **Load more** below it shows as
many more, up to 100, or for the latest commits up to the 1,000 commits read for the period.
The rows loaded are kept in the address until the repository or period changes.

The figures on top and the commits are kept as long as on the other statistics pages (5
minutes for 7 days), and the tabs' lists and the security alerts for a minute, so a reloaded
page does not ask GitHub again.

## Data sources

A GitHub connection offers these sources, filed under **GitHub** and the repository in the
designer's **Data source** tree. They are read from GitHub only while a screen shows them,
like an app's [connected sources](../data-sources.md#connected-sources).

| Source                           | Shape  | What it is                                                                 |
| -------------------------------- | ------ | -------------------------------------------------------------------------- |
| Open pull requests               | number | Open pull requests, drafts included, as they are now.                      |
| Pull requests waiting for review | number | Open pull requests that are not drafts and have no review yet.             |
| Pull requests merged             | number | Pull requests merged in the period.                                        |
| Failed workflow runs             | number | Workflow runs started in the period that failed.                           |
| Open issues                      | number | Open issues, pull requests not counted, as they are now.                   |
| CI on the default branch         | list   | One row: the latest workflow run on the default branch, and its result.    |
| Latest release                   | list   | One row: the newest published release and its day, or else the first tag.  |
| Commits                          | number | Commits on the default branch in the period.                               |
| Commits over time                | series | Commits on the default branch per bucket of the period, 0 when none.       |
| Commit authors                   | number | Distinct authors of the period's commits.                                  |
| Latest commits                   | list   | The 10 newest commits: first line of the message (author), and the time.   |
| Top contributors                 | list   | The 10 authors with the most commits in the period, and their count.       |
| Newest open pull requests        | list   | The 10 newest open pull requests: #number title (author, draft), the day.  |
| Pull request lead time (hours)   | number | Median hours from opened to merged, of pull requests merged in the period. |
| Stars                            | number | Stars, as they are now.                                                    |
| Forks                            | number | Forks, as they are now.                                                    |
| Watchers                         | number | Accounts watching the repository, as they are now.                         |
| Issues opened                    | number | Issues opened in the period. Needs **Issues**.                             |
| Issues closed                    | number | Issues closed in the period. Needs **Issues**.                             |
| Open bugs                        | number | Open issues labeled `bug`, as they are now. Needs **Issues**.              |
| Open Dependabot alerts           | number | Open Dependabot alerts, as they are now. Needs **Dependabot alerts**.      |
| Dependabot alerts by severity    | list   | Four rows: critical, high, medium, low. Needs **Dependabot alerts**.       |
| Open code scanning alerts        | number | Open code scanning alerts. Needs **Code scanning alerts**.                 |
| Open secret scanning alerts      | number | Open secret scanning alerts. Needs **Secret scanning alerts**.             |

Sources that need an optional permission are offered only when the token had it when the
repository was last connected. A security source whose feature the repository has turned off
says so on the source, rather than failing the connection.

### How commits are counted

Commits are those on the default branch whose **committer date** falls in the period, as
GitHub's commits list filters them (`since` and `until`), so a series buckets them by the
same date. A rebased or cherry-picked commit counts on the day it was committed again, not
the day it was first written. Buckets follow the period's resolution, as on the other
connectors' series (per 2 minutes for an hour up to per day for 30 days); buckets of a day
begin at midnight in the connection's time zone.

An author is the commit's GitHub account, or else the name written in the commit, or else
its email. The page and the commit sources read one list of the period's commits between
them; the count for the period before is asked on its own, in one call.

The commits list is read 100 a page, at most **10 pages**: a period counts at most **1000
commits**, and the count for the period before is held to the same ceiling, so the two
compare. The page says when the ceiling is reached. Open alerts are counted the same way, up
to 1000 of each kind.

The lead time is the median of the first 100 pull requests the search lists for the
period, so one call answers both the merged count and the lead time.
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
| ... are not enabled for the repository    | Turn the feature on, or give the token its permission, read-only.     |
| GitHub asks to slow down                  | A rate limit was reached. It is asked again shortly.                  |
| The GitHub token cannot be decrypted      | `APP_KEY` changed. Connect GitHub again.                              |
