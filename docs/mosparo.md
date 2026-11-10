# mosparo

Connect a project to a project in [mosparo](https://mosparo.io), the spam protection for
forms, and Lumberjack shows its valid and spam submissions on a page of its own and offers the
counts to screen [widgets](./widgets.md) as [data sources](./data-sources.md).

Lumberjack only reads mosparo, through its
[statistic API](https://documentation.mosparo.io/docs/api/statistic). That API counts whole
days, and only as far back as mosparo keeps its submissions (14 days by default), so that is as
far back as Lumberjack sees.

## Connecting

Team owners and admins connect mosparo from **Data sources**, under **Connectors**, or from
the **mosparo** page of a project that has none yet.

1. In mosparo, open the project's **Settings**, **General settings**, and find **API keys**.
2. In Lumberjack, choose **Connect** and give:

    | Field             | What it is                                                                    |
    | ----------------- | ----------------------------------------------------------------------------- |
    | **Name**          | What it is shown as, such as the form it protects.                            |
    | **Address**       | The https address you open mosparo at, such as `https://mosparo.example.com`. |
    | **Public key**    | The project's public key.                                                     |
    | **Private key**   | The project's private key.                                                    |
    | **Days begin in** | The time zone mosparo runs in, as it counts its days there.                   |

3. Choose **Connect**. Lumberjack asks mosparo once with the keys, and says on the private key
   if mosparo refuses them.

The keys are stored encrypted, never shown again, and only ever sent to the address given,
over https. The private key itself never travels: each call is signed with it. A private
address is refused unless `LUMBERJACK_DATA_SOURCES_ALLOW_PRIVATE=true`.

A project reads as many mosparo projects as you connect. Connecting the same public key again
renews its private key. With more than one, a picker beside the title switches between them.
Under the page's **Connection** tab, for team owners and admins:

- **Connect another mosparo project** opens the connect dialog.
- **Disconnect** removes this connection and its data sources.

## The mosparo page

Once connected, **mosparo** shows in the project's sidebar. mosparo counts whole days, so the
page offers 1, 7 or 30 days, today included, and no hour. For the period it shows the
valid and spam submissions, the share that was spam and the requests mosparo's security
delayed or blocked, each against as many days before, and when the last submission came. 30
days count only what mosparo still keeps, and days before that show no comparison. Below, a
chart shows the valid or spam submissions per day.

## Data sources

A mosparo connection offers these sources, filed under **mosparo** and the connection's name in
the designer's **Data source** tree. They are read from mosparo only while a screen shows them,
like an app's [connected sources](./data-sources.md#connected-sources).

| Source                                              | Shape  |
| --------------------------------------------------- | ------ |
| Valid submissions, spam submissions                 | number |
| Spam rate (%)                                       | number |
| Delayed requests, blocked requests                  | number |
| Valid submissions per day, spam submissions per day | series |

mosparo counts whole days, so a widget showing a mosparo source offers 24 hours, 7 days or 30
days, each read as that many days, today included: 24 hours is today. A widget left on an hour,
such as by the screen's own period, shows today and says **24h** in its corner. A series has a
point per day whatever the chart's resolution.

## When mosparo cannot be read

The connection and the mosparo page say why:

| Error                                 | What to do                                                             |
| ------------------------------------- | ---------------------------------------------------------------------- |
| mosparo refused the keys              | Check the public and private key in the project's settings in mosparo. |
| The address did not answer as mosparo | Check it is the address you open mosparo at.                           |
| The mosparo keys cannot be decrypted  | `APP_KEY` changed. Connect mosparo again.                              |
