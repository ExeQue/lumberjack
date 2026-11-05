# Status screens

A status screen is a wall of widgets for a TV or a monitor in the room: requests,
errors, latency, issues, queues, hosts and health checks, readable from across the
room and live, with nothing to click. A team has as many screens as it likes ("Ops",
"Checkout team"); every member sees, designs and deletes them, because a screen only
shows what each member can already see on the project pages.

![A status screen on a TV](./screenshots/screen-kiosk.png)

## The list of screens

**Screens** in the sidebar lists the team's screens. **Shown on** says how many have each
open right now (kiosks, paired TVs and share links, asked again every half minute), how
many paired TVs are set to show it, alone or in turn, and **Shared** when it has a share
link. **New screen** asks for a name and
a layout to start from, then opens the designer:

- **Overview**: traffic, errors, latency, issues, health, queues and hosts at a glance.
- **On call**: what needs attention: error rate, newest issues, failing checks, alerts.
- **Infrastructure**: CPU, memory and disk per host, agents and every health check.
- **Jobs and schedules**: failed jobs, queue sizes and how scheduled tasks ran.
- **Empty**: an 8 x 4 grid to fill yourself.

Every layout uses the same 8 x 4 grid; the designer changes it.

Every widget of a template is an ordinary widget: change, move or remove it after. Each row opens the screen full screen (**Open kiosk**, in a new tab),
in the designer (**Design**), or renames, duplicates or deletes it. A TV showing a
deleted screen says so instead of going on with old numbers.

![The list of screens](./screenshots/screens.png)

### Team templates

**More > Save as template** on a row keeps the screen as a template of the team, with a
name and a short description. **New screen** then offers it to every member next to the
built-in layouts, marked **Saved by the team**, with a thumbnail of its widgets. A
template holds the screen as it was when saved, in the same portable form as an export,
so later changes to the screen, or deleting it, do not change the template; a screen made
from it finds its projects and health checks by slug, and the notice names any the team
no longer has. Every member can save templates and use them; the **Team templates** list
below the screens lets team admins edit their name and description or delete them. The
built-in layouts stay as they are.

### Export and import

**Export**, under **More** on each row and in the designer's top bar, downloads the
screen as a JSON file named after it, such as `ops-wall.lumberjack-screen.json`, to keep
in a repository or bring to another team or another Lumberjack:

```json
{
    "format": "lumberjack-screen",
    "version": 1,
    "exported_at": "2026-11-04T10:00:00+00:00",
    "screen": {
        "name": "Ops wall",
        "cols": 4,
        "rows": 4,
        "aspect": "16:9",
        "theme": "schedule",
        "scope": {
            "projects": ["shop"],
            "environment": "production",
            "period": "24h"
        },
        "widgets": [
            {
                "id": "health",
                "type": "health",
                "source": "checks",
                "x": 0,
                "y": 3,
                "w": 2,
                "h": 1,
                "options": {
                    "checks": [
                        {
                            "project": "shop",
                            "environment": "production",
                            "slug": "database:pgsql"
                        }
                    ]
                },
                "scope": {},
                "rotate": []
            }
        ]
    }
}
```

The file holds the grid, every setting (aspect, orientation, fit, header, theme with its
light schedule and time zone, default scope) and every widget with its position, options,
scope and rotation. Projects are named by slug and health checks by their project's slug,
environment and slug (a monitored check, which has none, by its name), so the file does
not depend on ids. Nothing that grants access goes in: no share link, no displays, no ids.

**Import**, above the list, takes such a file, picked or pasted (at most 256 KB), and shows
what it holds before anything is made: name, grid, widgets, and the projects and checks
this team has no match for. **Import screen** creates it as a new screen of the current
team and opens the designer. Projects and checks are found by slug within the team; one
the team does not have is left out and the notice names it, so a widget covers the
projects or checks that remain, or all of them when none does. A file of another format
or version, or with widgets the designer would not save (an unknown source, cells used
twice, a grid past 12 x 12), is refused with the reason. Every member can export and
import, as every member can make screens.

**More > Copy to team...** does both in one step for another team you are in: pick the
team and the screen is made there as an import would make it, with the same notice of
what that team has no match for. The screen stays as it is in this team.

## The designer

The designer has three parts: the widgets you can add on the left, the screen as its
grid in the middle, and the settings of the selected widget on the right. Every change
saves itself a moment later, and every TV showing the screen follows within a second,
without reloading and without losing the data it shows. The top bar says whether the
last change is saved and on how many screens it is live right now.
**Reload TVs** next to it makes every kiosk, paired TV and share link showing the
screen load its page afresh, as after an update, each within three seconds at random so
a wall of TVs does not ask at once; it says how many screens it reached, and can be sent
again after ten seconds.

![The designer](./screenshots/screen-designer.png)

### The grid

A screen is a grid of 2 to 12 columns and 2 to 12 rows, and the TV shows exactly that
grid, so a widget's size in cells is its size on the TV. Each widget sits on the cells
you put it on; cells no widget covers simply stay empty on the TV.

**Aspect** next to Columns and Rows is the shape of the display the screen is made for:
16:9 (the default), 16:10, 4:3, 21:9 or 32:9, and the two buttons after it stand it
landscape or portrait (a 16:9 portrait screen is 9:16). The grid in the designer takes
that shape, as large as fits, so a widget's text reads as it will on that display.

The designer draws the screen as the TV does, header, gaps and type sizes included.
**Show header** in the screen settings turns the TV's header (the screen's name, its projects and period, how
many widgets need attention, live or offline, and the clock) on or off; without it the
grid takes the whole screen, and the offline notice lies over its top.

**Preview at** shows the screen as a TV of that resolution shows it, scaled down to fit
the designer: **Fit** (the default) draws it as large as fits; first the size each TV
showing this screen reports for its window ("Office TV, 1920 x 1080"), then the common
resolutions of the screen's aspect (1920 x 1080 and 3840 x 2160 for 16:9, turned for
portrait), or **Custom** with a width and height of your own. Moving,
resizing and selecting widgets work the same in the preview. It is a view setting of
this browser, kept per screen, not part of the screen.

- **Add**: drag a widget type from the left onto any free cell, or click it to add it
  in the first free spot (row by row from the top left). A new widget is 1 x 1, so any
  free cell takes it; resize it from there. The search field finds a type
  by what it can show, so "queue" finds Stat, Gauge, Chart and List.
- **Move**: drag a widget to other cells. While you drag, every cell of the grid shows
  as a drop zone and the widget's footprint follows the pointer: green where it fits,
  red where it would cover another widget or reach past the grid. Dropping onto another
  widget swaps the two (each keeps its size) when both then fit; dropping onto widgets
  that exactly fill the moved one's footprint (a 2 x 2 onto two 2 x 1) swaps it with all
  of them. Otherwise the drop is refused and the widget stays where it was.
- **Resize**: drag any edge or corner; sizes follow whole cells, and the left and top
  edges move the widget as they resize it. A widget only grows into free cells.
- **Keyboard**: with a widget focused, the arrow keys move it one cell, Shift and the
  arrows resize it (both only into free cells; a widget against the right or bottom edge
  keeps that edge and grows or shrinks on its other side), Delete removes it and Escape
  shows the screen's settings.

![Dragging a widget onto a free cell](./screenshots/screen-designer-drop.png)

Making the grid smaller shrinks widgets larger than it, and moves a widget that no
longer fits just far enough back inside when those cells are free. Any other widget is
kept, not deleted: the designer lists it above the grid as not fitting (it does not
show on the TV) until you drag it back onto free cells, make the grid larger again or
remove it.

**Show an incident** previews the screen in trouble (the first issue escalating, a
check down, values past their limits), so the colors and rotation can be judged before
it happens for real. **Live data** goes back to the real numbers.

![The designer previewing an incident](./screenshots/screen-designer-incident.png)

### Screen settings

With no widget selected (click the grid's background, the toolbar's Screen settings button, or press Escape anywhere) the right side
shows the screen's own settings: its name, the projects, environment and period every
widget shows unless it picks its own (no project ticked means every project of the team,
new ones included), the time zone for the clock and the theme schedule (left empty, each TV
uses its browser's own, so one screen shows local time in every office; a new screen starts
empty), whether the
header shows and whether its clock does, the [theme](#theme) and how the screen fits a display of another shape.

## Widgets

A widget is a way of showing something; what it shows is picked under **Shows**, grouped
by topic (traffic, issues, jobs and queues, hosts and agents, databases, health).

| Widget               | Shows                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stat                 | One number with its trend against an earlier period and a sparkline: requests, requests per minute (the last five minutes, now), error rate, p95, Apdex, open issues, users affected, failed jobs, queue size, queue workers (red at none), agents online, highest host CPU, memory or disk, current release, LCP p75, database size and connections.                                                                                                                                                                                                                                                                                                                            |
| Gauge                | One value on a half circle against its limits: requests per minute, error rate, p95, Apdex, host CPU, memory or disk, queue size, health uptime, the share of database connections in use.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| Chart                | A series over the period: requests and errors, requests, requests per minute, error rate, p95, Apdex, users affected, queue size, wait or workers, failed jobs, host CPU, memory or disk, LCP, database size or connections. It can split by route, queue, host, database check, environment, release or project.                                                                                                                                                                                                                                                                                                                                                                |
| List                 | Rows: latest issues, the alert feed, failed jobs by job, scheduled tasks, slowest routes, queues, hosts, agents, and the events of a calendar (see [Calendars](#calendars)).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| Health               | The health checks of the projects: a count at 1 x 1, a list when narrow (name, state and how long, in columns), cards when the widget is two or more cells wide (each like a single check's tile, one per grid cell the widget covers). The cards run to the widget's edges. What does not fit scrolls slowly into view, as in a list (sideways on a widget wider than tall); with **Scroll slowly** off it is counted at the end instead, with how many of those are down or degraded. With exactly one check picked, that check alone with its uptime, timeline and last message, and for a database check its connections (amber at 80% of the maximum, red at 90%) and size. |
| Clock and text       | The time (12 or 24 hour, its own time zone or the screen's, with seconds or the date), a note for the room, or both.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| QR code              | A link, a wifi network (name, password, security, hidden) or any text, as a QR code in the brand style, with an optional line under it such as "Guest wifi". It shows on share links too, so put nothing there that the link should not give away.                                                                                                                                                                                                                                                                                                                                                                                                                               |
| Image, video or page | An image (a camera's MJPEG stream too, loaded again every few minutes if you like), a video (MP4 or WebM, muted and looping) or a web page; a YouTube or Vimeo link plays muted and looping. Several addresses, one a line, take turns as a slideshow, on every TV at the same moment. Fit as CSS object-fit (contain, cover, fill, none, scale-down); without the space around it the media runs to the tile's edges. The TV loads the address itself, so it must be http(s), reachable from the TV, and https on an https Lumberjack. Pages that forbid framing stay blank.                                                                                                    |

A list of queues judges each queue like its [backlog alert](./alerts#queue-backlog): red
past the project's thresholds (its own for that queue, or the rule's: 1,000 jobs or 10
minutes by default), amber past half of them: the number of jobs waiting turns that color,
and a queue whose jobs wait too long says **Waiting** and for how long. A muted queue stays
calm. Limits set on the widget replace that and count on its size. Each row has the queue's
full name, its jobs waiting on the right, and how many workers run on it, where the agent
knows.
The database values read what [database health checks](./health#database-metrics) report:
the size is the latest each database reported, added up over the databases in scope, connections
the same; the gauge shows the database with the largest share of its connections in use
(amber at 80%, red at 90%). A database several servers report counts once.
A list of hosts shows each host once with CPU, memory and disk meters. The filter of a queue, host or database value is named after it (**Queue**, **Host**, **Database check**) and suggests the team's queues, hosts and database checks (by slug, such as `database:pgsql`) while you type; `*` still matches anything. Rows that do not fit scroll slowly down and back up, resting at each end; hovering stops it, and **Scroll slowly through rows that do not fit** under Rows turns it off.

Every widget with data names its period in the top right corner (1h, 24h, 7d, 30d), or how old the latest report is (**55s**, **6m**) for values that are the latest report (host CPU, memory and disk, queue sizes, agents, open issues, the current release, health, databases), which have no period to pick;
**Over** in the inspector picks it per widget, or follows the screen. **Show the period
in the corner** under Display turns the label off.

Each widget's settings are grouped in sections that open and close:

- **Value**, **Scale**, **Chart**, **Rows**, **Checks** or **Clock**: what the type
  itself offers, such as the amber and red limits of a stat or gauge (empty uses the
  defaults shown; shares go from 0 to 1, durations are in milliseconds), whether a gauge
  marks each limit on its arc (with red hidden, amber runs to the end), whether low
  values are bad (Apdex, uptime), what to compare with, decimals, a prefix and suffix,
  the chart style (bars, area or line, the lines smooth or straight) and how many series, its time axis, value axis and grid lines (each on or off), how many rows a list shows and in which order.
- **Rotation**: a stat, gauge, chart, list or health widget can show several values in
  turn on one tile. The inspector shows them as tabs at the top, one per value in the order they
  show, the widget's own source first. **+** after the tabs adds the next value not
  shown yet and opens its tab; **Shows** on that tab changes which one it is. A tab
  holds everything about its value: what it shows, its title, its period (**Over**),
  how many seconds it stays on screen, and the type's value settings (limits, low
  values are bad, compare, decimals, prefix and suffix, a gauge's scale, chart or list
  options) and its **Scope**, so one stat can show the requests of a route and then the
  size of a queue. On the first tab these are the widget's own; on the others, left empty,
  each shows what the widget uses ("Same as widget"). Drag a tab, or use the arrows
  above its settings, to change the order; **Remove this value** takes it out of the
  rotation. Moving a value to the front, or removing the first, makes it the widget's
  source and its settings the widget's, and the other values keep what they showed.
  In the designer the selected tile shows the value whose tab is open.

    Below the tabs, **Whole widget** holds what applies to every value: under
    **Rotation**, how often they switch (every 5 seconds to a minute), with a fade, a
    slide, a flip (as on a departures board) or none (the old value leaves with its title and label before the next comes in), whether a value that needs attention (amber or red) holds the tile
    until it clears, and a progress bar; then how often it updates (the rest
    of Display is behind the gear). Tiles with the same timing switch together. The
    progress bar runs along the bottom edge of the tile, growing from the center to the
    full width just as the next value takes over; it stands still while a value holds
    the tile or a tab is open in the designer, and does not show when the system asks
    for reduced motion. As stored in the screen's widgets, a `rotate` entry is a
    source id or `{"source": "p95", "seconds": 30, "title": "Latency", "options":
  {"red": 800}, "scope": {"period": "24h"}}`; the first value's seconds are the
    widget's `source_seconds` option.

    A health widget's values are sets of checks: each tab picks its own checks and the
    other settings under **Checks** (view, only problems, uptime, timeline, message,
    stale). Pick one check per tab and a 1 x 1 tile cycles through the checks, each
    named after its check; or pick a group per tab. **+** adds a tab with the next check
    not on a tab yet (with none picked once every check has one), and a tab is named
    after its title, else its check, else how many checks it shows (**3 checks**), else
    **All checks**. A tab with no check picked shows every check, not the widget's. A
    down or degraded check holds the tile while **Stay on a value that needs attention** is on. Stored, every health
    value names the source `checks`: `{"source": "checks", "seconds": 5, "options":
    {"checks": ["<check uuid>"]}}`.

    ![A rotating widget's value tabs](./screenshots/screen-designer-rotation.png)

- **Scope**, on each value's tab: the projects, environment and release (**All**,
  **Current only** or **Previous**) it shows,
  each the widget's (on the first tab the screen's) default unless set, and a filter
  named after what the value can narrow: the route, queue, job, host or database check
  (`*` matches anything, so `POST /checkout*` or `payments*`); a route filter also
  matches route names, such as `orders.*`. A value that narrows a route also has a
  **Domain**, suggesting the domains requests came in on in the last 7 days, such as
  `shop.example.com` (`*` works here too). A value with nothing to
  narrow has no filter, and one that narrows something else than the first value (a
  queue after a route) does not take the widget's filter or domain. A rotated value's scope is stored in its `rotate` entry, such as
  `{"source": "queue_size", "scope": {"filter": "bulk"}}`.
- **Display**: not in the inspector but behind the gear in the tile's top right corner,
  in place of the period label, which shows while the pointer is on the tile, it has focus or it is
  selected (never on the TV). The gear selects the widget and opens its display settings
  in a small panel beside it; Escape or a click elsewhere closes it, and changes save like
  any other. They are whether the title shows and how big, whether it sits on a card (off,
  it has no background or border while all is well, and gets them back while degraded,
  down or recovering), whether the tile is colored by
  its status (and whether a calm tile keeps its green border), the text size (fitted to
  the tile, or small to huge), alignment (left or centered, top or vertically centered),
  and whether to hide it while all is ok or while it has nothing to show. How often it
  updates (live, every minute, every 5 minutes) is about its data, so it stays in the
  inspector. The panel opens beside the tile (right of it, else left, else below or
  above), so the tile stays in view while it changes, follows it when the page scrolls,
  and scrolls itself when it does not fit.

    ![A widget's display settings behind its gear](./screenshots/screen-designer-display.png)

Older screens may still have an **Empty cell** widget, from when widgets filled the grid
in order and a gap needed a filler. It still works (dashed in the designer, invisible
on the TV), but it is no longer offered: a free cell is empty on its own, so it can be
removed.

### Calendars

A list can show a calendar's events: today's meetings next to the meeting room door, the
on-call rota, the week's releases. Add the calendar as a data source of a project
(**Data sources**, format **iCalendar (.ics)**) with the address it is published at in
iCal format: in Google Calendar the **Secret address in iCal format** under the
calendar's settings, in Outlook a calendar published as ICS, in iCloud a public
calendar's link (`webcal://` as `https://`), or any `.ics` address. Lumberjack fetches it
on its interval like any data source and records an error when the answer is not an
iCalendar feed. That address is a key to the calendar: after saving, the data sources page
shows it without its path, and screens, TVs and share links never get it.

Then pick **Calendar events** for a list widget and the data source under **Rows**. It
shows the events from **Days back** (today, yesterday or a week back) to **Days ahead**
(the rest of today, until tomorrow, a week or 30 days), in the screen's time zone (else
the server's), with repeating events expanded, their skipped and moved dates included,
and cancelled ones left out. With **Only what has not ended** (on by default) an event
leaves the list when it ends. Rows go by day, each day's all-day events first, then by
start: the start time (or **All day**), the title, and the place and end time where
there is room; an event on now says **Now** in amber. A list over more than one day puts
the weekday before the time. On a share link without names the rows show the times only.
**Rows** caps how many show, as in any list.

### Status colors

A widget with a state colors itself: a green border when all is ok, an amber border and
background when degraded or past the amber limit, red when down or past red, blue while
a check is recovering and gray when a check is stale. Widgets without a state (a chart,
the clock) keep a plain border. A down check turns the health widget red; a degraded or
recovering one colors only its row or card. A tile that turns red flashes once, so the
change is noticed; there is no sound.

## On the TV

`/present/screens/<id>` (**Open kiosk**) shows the screen full screen: no sidebar or menu,
large type, the cursor hidden after a few seconds without moving, and the whole picture
shifting a few pixels every few minutes so nothing burns into the panel. The header
shows how many widgets need attention, whether the screen is live, and the time.

On a display of another shape than the screen's aspect, **On a different screen** in the
screen's settings decides: **Keep its shape** (the default) lays the screen out at its
own shape as large as fits, centered, with the ground color around it, so it looks as
designed; **Fill the display** stretches it over the whole display. Within 2% of the
screen's shape both look the same. This holds for the kiosk, a paired TV and a share
link alike.

The data updates on its own: a widget whose projects change (a new issue, a failed
job, a health result) refetches within seconds, and every widget refetches every minute
in any case (every five, for widgets set to update every 5 minutes). Answers are kept
for 20 seconds, so a room full of TVs asks the database once, not once per TV.

When Lumberjack cannot be reached the screen says **Offline since** with the time,
dims the numbers it has, and tries again every 30 seconds; it never lets old numbers
look current.

A TV does not sign in: it is paired once, with a code, and shows its screen from then
on (see below). `/present/screens/<id>` is for a browser that is signed in anyway.

## Setting up a TV

Nothing long is ever typed on the TV. Pairing works like a streaming app: the TV shows
a code, and a phone or laptop that is signed in confirms it.

1. On the TV, open `/tv` on your Lumberjack (`lumberjack.example.com/tv`): short enough
   to type with a remote, in any browser (Fire TV Silk, Android TV, Fully Kiosk Browser,
   Chromium `--kiosk` on a Raspberry Pi). On a browser that was never paired it shows a
   six character code such as `K7Q-M2X` (no 0, O, 1 or I, so it reads well across the
   room), a QR code, and how long the code lasts.
2. On your phone, scan the QR code, or open `/pair` and type the code. Sign in if you
   are asked. The page shows which browser asks (**Fire TV · Silk**) and when the code
   was made, so you pair the right TV. Pick the screen, name the device ("Office TV")
   and press **Show on the TV**.
3. The TV shows the screen within a second, at `/tv/1`: the address of its first window.
   Make `/tv/1` the start page of the TV browser and it shows its screen again after
   every restart.

`/tv` itself is the landing page and never forwards by itself. On a browser that is
already paired it lists the device's windows as large buttons (arrow keys and Enter on
a remote), each with what it shows and its address, plus **Add another window**. Pick
one to go to it. For a screen that runs unattended, set its start page to its own
window's address (`/tv/1`, `/tv/2`, ...), not to `/tv`.

![A TV waiting to be paired](./screenshots/tv-pairing.png)

![Confirming the code on a phone](./screenshots/tv-pair-confirm.png)

A code lasts 10 minutes and works once; the TV shows a new one when it runs out. Only
team admins pair TVs. **Open on a TV** in the designer shows the same steps, with the
`/tv` address as a QR code for a TV stick or kiosk browser with a camera. Below them, team
admins see the TVs already paired: **Show here** puts the screen on one at once, in place of
what it showed (a playlist included), and each one's rotation can be set there too.

### Several screens on one computer

One computer can drive several screens: two monitors, or two browser windows side by
side. The computer is one device, and each browser window is one of its windows, up to
nine: `/tv/1` to `/tv/9`, all in the same browser.

1. Pair the first window as above. The device gets its name then ("Office PC").
2. In a second window, open `/tv` and press **Add another window** (or open `/tv/2`
   straight away). It shows a new code.
3. Confirm the code on your phone. Since this browser is already Office PC in the team,
   the page says **Add window 2 to Office PC** and asks only which screen window 2
   shows.

After confirming, the phone offers the address of the next free window. A window
that lost its cookie and is paired again gets its old settings back. The same browser
paired into another team becomes a device of its own there.

## Displays

**Screens > Displays** lists the team's paired devices, one card
each: its name, the browser it runs in, how many windows it has, and whether it is
online (any of its windows heard from in the last three minutes) or when it was last
seen. Under it, its windows: the window number, the screen it shows, the seconds per
screen and whether it holds on red, its theme, its rotation, the size of the window as
it last reported, and its own online status.

**Rotation** turns the picture 90° right, 180° or 90° left, for a TV hung sideways or
upside down that cannot turn its picture itself (a smart TV's browser, a signage
player). The TV turns at once; turned a quarter, the screen is laid out for the window
as it now stands, so a screen designed in portrait fills a landscape TV hung on its
side. A kiosk link without pairing takes the same as `?rotate=90` (or `180`, `270`).

![The team's displays](./screenshots/displays.png)

The page updates by itself: a window that is resized reports its new size within a
second or so, and a window that falls silent turns offline after three minutes, without
reloading the page.

Team admins change them; every member can see them.

For a device:

- **Name**: type a new one and leave the field.
- **Reload all**: every window of it loads its page afresh, as after an update (once
  every ten seconds).
- **Remove device**: every window of it goes back to showing a pairing code at once.
- Below its windows, the address of the next free window to add, `/tv/3` say.

For a window:

- **Shows**: another screen switches the window at once, so nobody has to touch it.
- **In turn**: several screens one after another in the same window, in the order you
  set, 10 seconds to an hour each (30 by default), fading from one to the next. While a
  screen has something red it stays until that clears, unless you turn that off.
- **Theme**: the screen's own theme, or always **Dark**, **Light** or **By time of
  day**, for a TV in a bright room showing a screen that is dark elsewhere.
- **Identify**: the TV shows its name large over the screen for 10 seconds, to tell it apart
  on a wall of them. **Open on a TV** in the designer offers it for each paired TV too.
- **Reload**: this window loads its page afresh.
- **Remove**: this window goes back to showing a pairing code; removing a device's last
  window removes the device.

The designer's **Preview at** lists the windows showing the screen by device and window
("Office PC, window 2, 1920 x 1080").

## Share links

Where pairing is not possible (a signage system that takes a URL, a page that embeds
one), a screen can have a share link: `/s/<key>`, readable without signing in. Open the
designer, press **Share** and **Share with a link**. The link can be copied, replaced by
a new one (**New link**) or turned off (**Stop sharing**); screens showing a link that
stopped working say **This screen was unshared**. Only team admins share screens.

Unless **Show issue titles and names** is ticked, a share link leaves out issue
titles, alert channel names and health check messages, and numbers hosts and agents
("host 1"), in lists and in charts split by them. Health checks keep their names: a
share link is made on purpose, and a check's name is the team's own to choose (rename
it in Health if it gives too much away). Route, job, queue and scheduled task names
show as they do on the metrics API's public stats. It may be shown inside another site's page. Every
change to a share link goes in the audit log. Prefer pairing: a paired TV needs no link
that could be passed on.

## QR codes

The QR codes (on the TV's pairing page, in **Open on a TV**, on **Pair a TV** in
Displays and for the share link) carry the Lumberjack mark, amber on the dark panel.

## What a TV keeps

A paired TV holds a display token, as a cookie only the server can read (and a copy
in the browser's local storage, in case a TV browser drops its cookies). It is never
part of an address, so it cannot leak through history, logs or a Referer, and only its
hash is stored. It lasts a year from the TV's last visit, can only read the screens of
its own team that the display shows, and stops working the moment the display is
removed. A code alone is useless in another browser: the token goes only to the
browser that showed the code. Looking up and confirming codes is limited per user and
per address.

## Theme

Each screen is **Dark** (the default), **Light**, or **Light by day, dark at night**
between two times in the screen's time zone. Changing it switches every open TV with a
short fade and no change of layout. Light is the same design with every color swapped
for its light twin, the status colors darkened enough to read on white.

![The same screen in the light theme](./screenshots/screen-kiosk-light.png)
