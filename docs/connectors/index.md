# Connectors

Lumberjack can read outside services itself, without an app of yours in between, through
connectors. Connect one to a project, and it gets a page of its own under
**Connectors** in the project's sidebar and offers its figures to screen
[widgets](../widgets.md) as [data sources](../data-sources.md).

Connectors are grouped by category, the same tags the **Connectors** page filters by. A
connector is listed under its main category; its other tags are in the last column.

### Analytics

Visitors and traffic of a website.

| Connector           | What it reads                                | Tags        |
| ------------------- | -------------------------------------------- | ----------- |
| [Umami](./umami.md) | The visitors of a website, from Umami's API. | `Analytics` |

### Development

The work on a code repository and its pipelines.

| Connector             | What it reads                                                                                    | Tags                     |
| --------------------- | ------------------------------------------------------------------------------------------------ | ------------------------ |
| [GitHub](./github.md) | The pull requests, commits, workflow runs, releases, issues and security alerts of a repository. | `Development` `Security` |

### Forms

What comes in through the forms on a website.

| Connector               | What it reads                                             | Tags               |
| ----------------------- | --------------------------------------------------------- | ------------------ |
| [mosparo](./mosparo.md) | The valid and spam form submissions of a mosparo project. | `Forms` `Security` |

### Payments

Money taken and paid out.

| Connector                         | What it reads                                                              | Tags                  |
| --------------------------------- | -------------------------------------------------------------------------- | --------------------- |
| [Vipps MobilePay](./mobilepay.md) | The revenue, refunds, fees and payouts booked on a Vipps MobilePay ledger. | `Payments` `Commerce` |

`Security` (spam held off, security alerts) and `Commerce` (sales) are tags only, so far
on connectors listed under another category.

## The Connectors page

**Connectors** in the project's sidebar opens the list of every connector, and its chevron
folds out the ones the project has connected. Each card shows what the connector reads, its
tags, and whether it is connected. The connected ones come first, then the rest, each by name.

A connected card shows how many connections it has, and how many of them fail. The
**n connections** button opens them all, each with its status (`OK`, `Failing` or
`Not checked yet`), when it was last checked or the error it failed on, and a search once
there are more than eight.

- **Search** matches a connector's name, description and tags.
- The **tags** (`Analytics` `Commerce` `Development` `Forms` `Payments` `Security`) narrow the list
  to the connectors with any of the tags picked.

The search and the tags are kept in the address, so a link shows the same list. With
nothing matching, **Clear filters** shows them all again.

Every member of the team sees the page; only owners and admins can connect.

## Connecting

Team owners and admins choose **Connect** on a connector's card (**Connect another** once
it is connected), or connect from the connector's page in a project that has none yet. Credentials are stored encrypted,
never shown again, and only ever sent to the address given, over https. A private address is
refused unless `LUMBERJACK_DATA_SOURCES_ALLOW_PRIVATE=true`.

A connector can be connected as often as needed, such as one Umami website, one mosparo
project, one GitHub repository or one Vipps MobilePay ledger each time. With more than one, a picker beside the page's title switches between them.

## The connector's page

Each page shows its key figures on top, each against the period before, and its sections in
tabs below; the tab is kept in the address. Under the **Connection** tab, team owners and
admins see where the connection reads from, and can:

- **Connect another** service of the same kind, through the same dialog as the first.
- **Disconnect**, which removes the connection and its data sources, so widgets showing them
  go empty.

## Data sources

A connector's sources are filed under its name and the connection's name in the designer's
**Data source** tree, and on **Data sources** each connection lists its sources below it,
folded away until opened. They are read from the service only while a screen shows them, like
an app's [connected sources](../data-sources.md#connected-sources).

A service that cannot answer for every period offers only those it can: mosparo counts whole
days and Vipps MobilePay books whole days, so their sources have no hour.

## Missing a service?

If a service you use is not on the list, you are welcome to
[open an issue](https://github.com/ExeQue/lumberjack/issues) asking for it, or to
[submit a pull request](https://github.com/ExeQue/lumberjack/pulls) adding it.
