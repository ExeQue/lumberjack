# Connectors

Lumberjack can read outside services itself, without an app of yours in between, through
connectors. Connect one to a project, and it gets a page of its own under
**Connectors** in the project's sidebar and offers its figures to screen
[widgets](../widgets.md) as [data sources](../data-sources.md).

| Connector               | What it reads                                             |
| ----------------------- | --------------------------------------------------------- |
| [Umami](./umami.md)     | The visitors of a website, from Umami's API.              |
| [mosparo](./mosparo.md) | The valid and spam form submissions of a mosparo project. |

## Connecting

Team owners and admins connect a service from **Data sources**, under **Connectors**, or
from the connector's page in a project that has none yet. Credentials are stored encrypted,
never shown again, and only ever sent to the address given, over https. A private address is
refused unless `LUMBERJACK_DATA_SOURCES_ALLOW_PRIVATE=true`.

A connector can be connected as often as needed, such as one Umami website or one mosparo
project each time. With more than one, a picker beside the page's title switches between them.

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
days, so its sources have no hour.

## Missing a service?

If a service you use is not on the list, you are welcome to
[open an issue](https://github.com/ExeQue/lumberjack/issues) asking for it, or to
[submit a pull request](https://github.com/ExeQue/lumberjack/pulls) adding it.
