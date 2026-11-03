# Status screens

A status screen is a wall of widgets for a TV or a monitor in the room: requests,
errors, latency, issues, queues, hosts and health checks, readable from across the
room and live, with nothing to click. A team has as many screens as it likes ("Ops",
"Checkout team"); every member sees, designs and deletes them, because a screen only
shows what each member can already see on the project pages.

![A status screen on a TV](./screenshots/screen-kiosk.png)

## The list of screens

**Screens** in the sidebar lists the team's screens. **New screen** asks for a name and
opens the designer. Each row opens the screen full screen (**Open kiosk**, in a new tab),
in the designer (**Design**), or renames, duplicates or deletes it. A TV showing a
deleted screen says so instead of going on with old numbers.

![The list of screens](./screenshots/screens.png)

## The designer

The designer has three parts: the widgets you can add on the left, the screen as its
grid in the middle, and the settings of the selected widget on the right. Every change
saves itself a moment later, and every TV showing the screen follows within a second,
without reloading and without losing the data it shows. The top bar says whether the
last change is saved and on how many screens it is live right now.

![The designer](./screenshots/screen-designer.png)

### The grid

A screen is a grid of 2 to 12 columns and 2 to 6 rows, and the TV shows exactly that
grid, so a widget's size in cells is its size on the TV. Widgets fill the grid in order,
row by row, and keep their order: an empty cell holds its place.

- **Add**: drag a widget type from the left onto the grid (before the widget you drop
  it on, or at the end), or click it to add it at the end. The search field finds a type
  by what it can show, so "queue" finds Stat, Gauge, Chart and List.
- **Move**: drag a widget onto another to put it there.
- **Resize**: drag its bottom right corner; sizes follow whole cells. The **Size**
  buttons in its settings are shortcuts for 1 x 1, 2 x 1, 2 x 2 and 4 x 2.
- **Keyboard**: with a widget focused, the arrow keys move it, Shift and the arrows
  resize it, Delete removes it and Escape shows the screen's settings.

When the widgets need more cells than the grid has, the designer says how many do not
fit; those are dimmed and do not show on the TV. Making the grid smaller shrinks widgets
that would be larger than it.

**Show an incident** previews the screen in trouble (the first issue escalating, a
check down, values past their limits), so the colors and rotation can be judged before
it happens for real. **Live data** goes back to the real numbers.

![The designer previewing an incident](./screenshots/screen-designer-incident.png)

### Screen settings

With no widget selected (click the grid's background, or press Escape) the right side
shows the screen's own settings: its name, the projects, environment and period every
widget shows unless it picks its own (no project ticked means every project of the team,
new ones included), and the time zone for the clock and the theme schedule.

## Widgets

A widget is a way of showing something; what it shows is picked under **Shows**.

| Widget         | Shows                                                                                                                                                                                                                           |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stat           | One number with its trend against an earlier period and a sparkline: requests, error rate, p95, Apdex, open issues, users affected, failed jobs, queue size, agents online, highest host memory, current release, LCP p75.      |
| Gauge          | One value on a half circle against its limits: error rate, p95, Apdex, host CPU, memory or disk, queue size, health uptime.                                                                                                     |
| Chart          | A series over the period: requests and errors, requests, error rate, p95, Apdex, users affected, queue size or wait, failed jobs, host CPU or memory, LCP. It can split by route, queue, host, environment, release or project. |
| List           | Rows: latest issues, the alert feed, failed jobs by job, scheduled tasks, slowest routes, queues, hosts, agents.                                                                                                                |
| Health         | The health checks of the projects: a count at 1 x 1, a list when narrow, small cards when two or more fit side by side. With exactly one check picked, that check alone with its uptime, timeline and last message.             |
| Clock and text | The time (12 or 24 hour, any time zone, with seconds or the date), a note for the room, or both.                                                                                                                                |
| Empty cell     | A gap of any size; dashed in the designer, invisible on the TV.                                                                                                                                                                 |

Each widget's settings are grouped in sections that open and close:

- **Value**, **Scale**, **Chart**, **Rows**, **Checks** or **Clock**: what the type
  itself offers, such as the amber and red limits of a stat or gauge (empty uses the
  defaults shown; shares go from 0 to 1, durations are in milliseconds), whether low
  values are bad (Apdex, uptime), what to compare with, decimals, a prefix and suffix,
  the chart style and how many series, how many rows a list shows and in which order.
- **Rotation**: a stat, gauge, chart or list can show several values in turn on one
  tile. Pick them under **Rotate through**; the title follows the value shown. They
  switch every 5 seconds to a minute, with a fade, a slide or neither, and all tiles of
  a screen switch together. A value that needs attention (amber or red) holds the tile
  until it clears. In the designer the selected tile stays on its first value.
- **Scope**: the projects, environment, period and release the widget shows, each the
  screen's default unless set, and a filter on the route, queue, job or host (`*`
  matches anything, so `POST /checkout*` or `payments*`).
- **Display**: whether the title shows, whether the tile is colored by its status,
  the text size (fitted to the tile, or small to huge), alignment, how often it updates
  (live, every minute, every 5 minutes), and whether to hide it while all is ok or while
  it has nothing to show.

### Status colors

A widget with a state colors itself: a green border when all is ok, an amber border and
background when degraded or past the amber limit, red when down or past red, blue while
a check is recovering and gray when a check is stale. Widgets without a state (a chart,
the clock) keep a plain border. A down check turns the health widget red; a degraded or
recovering one colors only its row or card. A tile that turns red flashes once, so the
change is noticed; there is no sound.

## On the TV

`/screens/<id>` (**Open kiosk**) shows the screen full screen: no sidebar or menu,
large type, the cursor hidden after a few seconds without moving, and the whole picture
shifting a few pixels every few minutes so nothing burns into the panel. The header
shows how many widgets need attention, whether the screen is live, and the time.

The data updates on its own: a widget whose projects change (a new issue, a failed
job, a health result) refetches within seconds, and every widget refetches every minute
in any case (every five, for widgets set to update every 5 minutes). Answers are kept
for 20 seconds, so a room full of TVs asks the database once, not once per TV.

When Lumberjack cannot be reached the screen says **Offline since** with the time,
dims the numbers it has, and tries again every 30 seconds; it never lets old numbers
look current.

For now a TV signs in like any browser. Pairing a TV with a code, and share links for
signage systems, come next.

## Theme

Each screen is **Dark** (the default), **Light**, or **Light by day, dark at night**
between two times in the screen's time zone. Changing it switches every open TV with a
short fade and no change of layout. Light is the same design with every color swapped
for its light twin, the status colors darkened enough to read on white.

![The same screen in the light theme](./screenshots/screen-kiosk-light.png)
