# Data sources

A data source is an external endpoint that Lumberjack fetches on an interval. Screen
[widgets](./widgets.md) show what it answers: a number from a JSON API, a Prometheus
metric, or the events of a calendar.

## Where to find them

Data sources belong to a project. Team owners and admins open them from the project's
settings menu, under **Data sources**. Members see **Data sources** in the sidebar and
can read the list, but not change it.

A widget on any screen of the team can use the data sources of every project in the
team.

## Adding a data source

1. Click **Add data source**.
2. Fill in the form:

   | Field               | What to enter                                                                                    |
   | ------------------- | ------------------------------------------------------------------------------------------------ |
   | **Name**            | A name to pick it by in widgets.                                                                 |
   | **URL**             | An `http` or `https` address that Lumberjack sends a `GET` request to. It must be public.         |
   | **Format**          | **JSON**, **Prometheus** or **iCalendar (.ics)**. See [Formats](#formats).                       |
   | **Interval**        | How often to fetch it, in minutes. At least 1.                                                   |
   | **Timeout**         | How long to wait for an answer, in seconds. At most 15.                                          |
   | **Request headers** | Optional headers sent with each request, such as `Authorization: Bearer …` or an API key header. |

3. Click **Add**. The source is fetched within a minute.

Put credentials in a header, not in the URL. A URL with a user name or password is
rejected.

### Request headers

Header values are stored encrypted and never shown again. The list shows only the
header names. To change a source later, leave a value empty to keep it.

Headers are sent only to the server in the URL. When a redirect leads to another
server, the headers are left out. When you edit the URL to point at another server,
give each header its value again.

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

- its name, format and URL. Values in the URL's query are hidden as `***`. For a
  calendar, the path is hidden too, as it is the key to the calendar.
- the names of its headers;
- **Latest response**: the start of the last answer, to help you write a JSONPath or
  selector;
- its **Status**: **Pending** before the first fetch, then **OK** or **Failed**;
- the **Last fetch**, and for a failed source the error and when it was **Last OK**;
- its **Interval**.

Team owners and admins have three actions:

- **Fetch now** fetches the source right away, without waiting for its interval.
- **Edit** changes its settings.
- **Delete** deletes it. Widgets that show it go empty.

## When a fetch fails

A failed fetch marks the source **Failed** and shows why:

| Error                                                 | What to do                                                       |
| ----------------------------------------------------- | ---------------------------------------------------------------- |
| The server answered HTTP 401 (or another status)      | Check the URL and the headers.                                   |
| The response is larger than 1024 KB                   | Point the URL at a smaller answer.                               |
| The response is not valid JSON                        | Check the URL, or pick another format.                           |
| The response has no Prometheus samples                | Use the text format, lines like `name{label="value"} 1`.         |
| The response is not an iCalendar feed                 | Use an address that answers with text starting `BEGIN:VCALENDAR`. |
| Its header values cannot be decrypted                 | Save the header values again.                                    |
| A connection error or timeout                         | Check that the server is reachable and answers within the timeout. |

Widgets keep showing the last good answer. The next fetch runs on the interval as usual.

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
  or a Prometheus source with a **Selector**. **Configure** shows the latest answer, so
  you can click the value you want. See [External JSON](./widgets.md#external-json).
- A **list** with **Calendar events**, or a **countdown**, reads an iCalendar source.
  See [Calendars](./widgets.md#calendars) and [Countdown](./widgets.md#countdown).

Charts read the source's history: each answer up to 64 KB is kept, 30 days by default.
See [retention](./projects#retention), under **Data source history**.

## Limits

| What                       | Limit                                         |
| -------------------------- | --------------------------------------------- |
| Name                       | 150 characters                                |
| URL                        | 2000 characters                               |
| Interval                   | 1 to 1440 minutes                             |
| Timeout                    | 1 to 15 seconds                               |
| Request headers            | 20                                            |
| Answer size                | 1 MB, 4 MB for an iCalendar feed              |
| Answer kept for charts     | 64 KB. Larger answers are only used as latest |
| Redirects                  | 3                                             |
| **Fetch now**              | 10 a minute                                   |
