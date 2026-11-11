# Data sources

A data source is an external endpoint that Lumberjack fetches on an interval, for screen
[widgets](./widgets.md) to show and [health checks](./health.md) to hold against limits: a
number from a JSON API, a Prometheus metric, or the events of a calendar.

## Where to find them

Data sources belong to a project. Team owners and admins open them from the project's
settings menu, under **Data sources**. Members see **Data sources** in the sidebar and
can read the list, but not change it. A widget on any screen of the team can use the data
sources of every project in the team.

## Adding a data source

1. Click **Add data source** and fill in the form:

    | Field               | What to enter                                                                             |
    | ------------------- | ----------------------------------------------------------------------------------------- |
    | **Name**            | A name to pick it by in widgets.                                                          |
    | **URL**             | An `http` or `https` address that Lumberjack sends a `GET` request to. It must be public. |
    | **Format**          | **JSON**, **Prometheus** or **iCalendar (.ics)**. See [Formats](#formats).                |
    | **Interval**        | How often to fetch it, in minutes. At least 1.                                            |
    | **Timeout**         | How long to wait for an answer, in seconds. At most 15.                                   |
    | **Request headers** | Optional headers sent with each request, such as `Authorization: Bearer …`.               |

2. Click **Add**. The source is fetched within a minute.

Put credentials in a header, not in the URL. A URL with a user name or password is
rejected.

### Request headers

Header values are stored encrypted and never shown again. When editing a source, leave a
value empty to keep it.

Headers are sent only to the server in the URL, not after a redirect to another server.
When you edit the URL to point at another server, give each header its value again.

> [!WARNING]
> Header values are encrypted with `APP_KEY`. When `APP_KEY` changes, a source with
> headers fails with "Its header values cannot be decrypted". Save its header values
> again.

## Formats

- **JSON**: any JSON answer. A widget picks a value from it with a JSONPath, such as
  `$.data.total`.
- **Prometheus**: the Prometheus text format, as served on `/metrics`. A widget picks a
  metric with a selector, such as `http_requests_total{status="500"}`. Counters are
  shown per minute.
- **iCalendar (.ics)**: a calendar feed, for a list of events or a countdown. Use the
  calendar's secret address in iCal format from Google, Outlook or iCloud. A `webcal://`
  address works too.

## The list

Each source shows:

- its name, format and URL. Values in the URL's query are hidden as `***`, and for a
  calendar the path too.
- the names of its headers;
- **Latest response**: the start of the last answer;
- its **Status**: **Pending** before the first fetch, then **OK** or **Failed**;
- the **Last fetch**, and for a failed source the error and when it was **Last OK**;
- its **Interval**.

Team owners and admins can **Fetch now**, **Edit** and **Delete** a source. Widgets that
show a deleted source go empty.

## When a fetch fails

A failed fetch marks the source **Failed** and shows why:

| Error                                                       | What to do                                                         |
| ----------------------------------------------------------- | ------------------------------------------------------------------ |
| The server answered HTTP 401 (or another status)            | Check the URL and the headers.                                     |
| The response is larger than 1024 KB (4096 KB for iCalendar) | Point the URL at a smaller answer.                                 |
| The response is not valid JSON                              | Check the URL, or pick another format.                             |
| The response has no Prometheus samples                      | Use the text format, lines like `name{label="value"} 1`.           |
| The response is not an iCalendar feed                       | Use an address that answers with text starting `BEGIN:VCALENDAR`.  |
| Its header values cannot be decrypted                       | Save the header values again.                                      |
| A connection error or timeout                               | Check that the server is reachable and answers within the timeout. |

Widgets keep showing the last good answer.

## Private addresses

Like [health checks](./health#private-networks), a data source must reach a public
address. A URL that resolves to a private, loopback or otherwise reserved address is
rejected, also after a redirect. To fetch from internal services, set:

```dotenv
LUMBERJACK_HEALTH_ALLOW_PRIVATE=true
```

## Using a data source in a widget

In the screen designer, pick the widget, then the source under **Data source**:

- A **stat**, **gauge**, **chart** or **list** reads a JSON source with a **JSONPath**,
  or a Prometheus source with a **Selector**. See [Data source](./widgets.md#data-source).
- A **list** with **Calendar events**, or a **countdown**, reads an iCalendar source.
  See [Calendars](./widgets.md#calendars) and [Countdown](./widgets.md#countdown).

Charts read the source's history: each answer up to 64 KB is kept, 30 days by default.
See [retention](./projects#retention), under **Data source history**.

## Connectors

Lumberjack can also read outside services itself, such as Umami, mosparo and GitHub. See
[Connectors](./connectors/).

## Connected sources

An app using the Laravel package can offer its own sources: a figure, a series, a list or a
table, computed by the app for the period a screen shows. See
[data sources in the package](https://github.com/ExeQue/laravel-lumberjack/blob/main/docs/data-sources.md).

1. Under **From your apps**, choose **Connect app**. Give the environment and the https address
   of the endpoint, such as `https://your-app.example/lumberjack/datasources`.
2. The pull token is shown once. Set it as `LUMBERJACK_PULL_TOKEN` in the app.
3. Lumberjack asks the app every five minutes which sources it has. Until it answers, the
   connection reads **Waiting for the app**.

Each connection lists its sources below it, folded away until you open them.

Nothing goes through the agent: Lumberjack pulls it all, signing every call with the token,
which never travels itself. It only uses https, and the app answers 404 to anything else. A
source is pulled one per call, only while a screen shows that period and about 12 minutes
after, or a health check reads it over that period, every five minutes or as often as the app caches it (`#[CacheFor]`), up to once a day and at
least once per the length of the period.
One app gets at most four pulls queued per minute, so with many sources on screens they refresh less often. Periods a health check reads go first, as a late pull fails a check but only ages a screen.
An app that moved or works in another time zone is changed with **Edit connection**; its token, sources and widgets stay.

An app can file a source under a group with `#[Group('Events', 'Storage')]`, from version 0.6.0 of the package (up to 3 levels of 40 characters). The data sources page groups it the same way, and **Data source** in the designer, a tree you can search, lists it under its project and group, such as **Shop > Events > Storage**. A source without a group is listed under its project alone.

Lumberjack decides how fine a series is, unless a chart sets its own **Resolution**: one point per 2 minutes over an hour, an hour over 24 hours, 6 hours over 7 days and a day over 30 days. Each connection has a **Time zone**, where those days begin. The app groups its points by what it is asked, from version 0.3.0 of the package. A day's bar on a chart is named by its date in that time zone, so a source has to begin its days at that zone's midnight.
A failed pull is tried again after five minutes, then 10, 20, 40 and every 80 minutes until it answers.
A private address is refused even with `LUMBERJACK_HEALTH_ALLOW_PRIVATE` on.
`LUMBERJACK_DATA_SOURCES_ALLOW_PRIVATE=true` allows it.

**Rotate token** makes a new one. Pulls go on with the one the app holds, and change over by
themselves within five minutes of the app answering to the new one.

| Status              | Meaning                                                                                                                                                                                            |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Connected           | Pulls work for what screens show and health checks read now, or nothing asks for its periods.                                                                                                      |
| Waiting for the app | It has never given a valid answer: it is not deployed with the token yet, or the address is wrong. The reason is shown.                                                                            |
| Failing             | The last call for a period on a screen or in a health check failed; the reason is shown under it. A screen keeps what it last got, with its real age.                                              |
| No longer offered   | A complete list from the app no longer has this source. It comes back if it returns, or can be removed. An app that lists nothing at all archives nothing, as an empty list is more often a fault. |

Each connection is one environment, so the same key can exist in several.

## Limits

An app can offer 200 sources and a project hold 1000 connected ones, counting those no longer offered until removed or 30 days old, and 20 connections. Past that, the connection says what was left out. The table is for endpoints added by hand. A connected source follows no redirects, may answer
up to 4 MB, and keeps up to 256 KB per period.

| What                   | Limit                                         |
| ---------------------- | --------------------------------------------- |
| Name                   | 150 characters                                |
| URL                    | 2000 characters                               |
| Interval               | 1 to 1440 minutes                             |
| Timeout                | 1 to 15 seconds                               |
| Request headers        | 20                                            |
| Answer size            | 1 MB, 4 MB for an iCalendar feed              |
| Answer kept for charts | 64 KB. Larger answers are only used as latest |
| Redirects              | 3                                             |
| **Fetch now**          | 10 a minute                                   |
