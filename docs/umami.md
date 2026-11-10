# Umami

Connect a project to its website in [Umami](https://umami.is), and Lumberjack shows the
visitors on a page of its own, beside what it saw of the same pages itself, and offers the
figures to screen [widgets](./widgets.md) as [data sources](./data-sources.md).

Lumberjack only reads Umami. Visitors keep being tracked by Umami's own script.

## Connecting

Team owners and admins connect Umami from **Data sources**, under **Connectors**, or from
the **Umami** page of a project that has none yet.

1. Choose **Connect**, and where Umami runs:

    | Where Umami runs | How Lumberjack signs in                                                                                                                               |
    | ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
    | **Umami Cloud**  | An API key, made in Umami Cloud under **Settings**, **API keys**.                                                                                     |
    | **Self-hosted**  | The https address you open Umami at, and an API key or a user name and password. A user who signs in with two-factor authentication needs an API key. |

2. Choose **Find websites**. The list holds your own websites and those of each of your
   Umami teams, grouped by team. An administrator who has none sees every website on the
   instance. If the list is empty, give the website ID: it is in the address of the
   website's page in Umami, after `/websites/`.
3. Pick the website and the **Time zone** its days begin in, and choose **Connect**.

The key or password is stored encrypted, never shown again, and only ever sent to the
address given, over https. A private address is refused unless
`LUMBERJACK_DATA_SOURCES_ALLOW_PRIVATE=true`.

A project reads as many websites as you connect, each with its own connection, data sources
and notes. Connecting a website the project already reads renews its credentials. Under the
page's **Connection** tab:

- **Connect another Umami** opens the connect dialog, for a website on another Umami or
  under other credentials.
- **Add a website** connects another website the same credentials can read.
- **Change website** points this connection at another website; screens showing its sources
  go on with the new one.
- **Disconnect** removes this website's connection and its data sources.

With more than one website, a picker beside the title switches between them. In the screen
designer, sources are listed under **Umami** and the website's name.

## The Umami page

Once connected, **Umami** shows in the project's sidebar. The period picker and every
figure work like the other statistics pages. On top are visitors, visits, page views, views
per visit, bounce rate and time per visit, each against the period before, and how many are
on the site right now.

| Tab            | What it shows                                                                                                                                                                                                                      |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Overview**   | Visitors or page views per bucket, with a dashed line for each release Lumberjack saw go out and each [note](#notes), and the busiest hours of the week over the last four weeks.                                                  |
| **Pages**      | Pages, entry and exit pages, each with its domain and its LCP as the [browser script](./performance.md#browser) measured it, and the domains themselves. Ids in a path are folded, so `/orders/12` is matched with `/orders/{id}`. |
| **Sources**    | Referrers, channels and the UTM source, medium and campaign.                                                                                                                                                                       |
| **Audience**   | Countries, devices, browsers, operating systems and languages.                                                                                                                                                                     |
| **Events**     | Custom events, with the share of the period's visitors who triggered each.                                                                                                                                                         |
| **Connection** | The connection, for team owners and admins.                                                                                                                                                                                        |

Click a row to show only what matches it, such as one page on its domain or one campaign.
The filters show above the figures; click one to remove it.

Each table shows the ten values seen most. **Search** above it asks Umami for the values
containing what you type, over the whole period, not only those shown; a UTM value is found by
its [name](#naming-utm-values) too. **Load more** below it shows ten more, up to 200.

The tab, the website, the period, filters, searches and rows loaded are kept in the address,
so a reload or a shared link opens the same view. The website is part of the path, such as
`/projects/…/connectors/umami/…#sources`.

Umami counts bounces and time per visit for the whole site and per channel, but leaves them at
0 for most other breakdowns. A table hides those columns when Umami counted none.

### Notes

Mark a moment on the traffic chart, such as a campaign's launch or a change to the site:
under **Overview**, choose **Add note** below the chart and give it a date, an optional time
and a note. A note without a time covers the whole day. Notes are Umami annotations, so they
show in Umami's own charts too, and notes written in Umami show here. Team owners and admins
add and delete notes; everyone on the team sees them.

### Naming UTM values

A UTM value such as a Meta campaign's numeric id says little. Under **Sources**, choose
**Name** beside a UTM value and give it a name. The name shows wherever the value does: on
the page, in the lists screens show and as the name of the campaign's data source. Save an
empty name to forget it.

## Data sources

An Umami connection offers these sources, filed under **Umami** and the website's name in the
designer's **Data source** tree, and under the connection on the **Data sources** page. They are read from Umami only while a screen shows them, like an
app's [connected sources](./data-sources.md#connected-sources).

| Source                                                                                                                                         | Shape          |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| Visitors, visits, page views                                                                                                                   | number         |
| Bounce rate (%), time per visit (seconds)                                                                                                      | number         |
| Visitors right now                                                                                                                             | number         |
| Visitors over time, page views over time                                                                                                       | series         |
| Top pages, entry pages, referrers, channels, countries, browsers, operating systems, devices, languages, events, UTM sources and UTM campaigns | list           |
| Each custom event seen in the last 30 days: its count, and the count over time                                                                 | number, series |
| Each UTM campaign seen in the last 30 days, or given a name: its visitors, and its visitors over time                                          | number, series |

A campaign that has a name stays a source when it goes quiet, under the website's **Campaigns**.

## When Umami cannot be read

The connection and the Umami page say why:

| Error                                             | What to do                                                                     |
| ------------------------------------------------- | ------------------------------------------------------------------------------ |
| Umami refused the credentials                     | Check the API key, or the user name and password, and connect again.           |
| Umami does not know this website                  | The website was deleted, or the address is not Umami's. Change website.        |
| Umami asks to slow down                           | Umami Cloud allows 50 calls per 15 seconds per key. It is asked again shortly. |
| This user signs in with two-factor authentication | Make an API key in Umami and use that.                                         |
| The credentials cannot be decrypted               | `APP_KEY` changed. Connect Umami again.                                        |
