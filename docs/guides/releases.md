# Check whether a release went well

See whether a new release brought new errors, regressions, more server errors or slower
responses than the one before.

## 1. Report the release

Every request, error and job carries the release it ran on.

**Laravel SDK.** The package uses the current Git commit, or the commit Composer installed
`vendor` from when the deploy has no `.git`. To set it yourself, such as to a version
number (at most 64 characters), put it in the app's environment:

```dotenv
LUMBERJACK_RELEASE=v2.14.0
```

**Browser script.** Give the script tag the same release, so browser errors and Web
Vitals carry it too:

```html
<script
    src="https://lumberjack.example/sdk/v1.js"
    data-key="lj_pub_..."
    data-environment="production"
    data-release="v2.14.0"
    defer
></script>
```

The Laravel package's `@lumberjack` Blade directive fills in the release for you. See
[Projects](../projects.md) for the script's attributes.

**When it works:** the release shows on the project's **Releases** page once the first
requests on it come in.

## 2. Upload the source maps

A browser error from a bundled build points at minified code. Upload the build's source
maps in the deploy, after the build, and browser errors show your own files, lines and
functions. The same error also stays one issue across deploys.

**Laravel SDK.** Run the command after `vite build`:

```bash
npm run build
php artisan lumberjack:sourcemaps --delete
```

It tags each map with the app's release, and `--delete` removes the maps from `public`,
so your source is not served to everyone. See
[`lumberjack:sourcemaps`](https://exeque.github.io/laravel-lumberjack/docs/commands#lumberjack-sourcemaps).

**Anything else.** Send each `.map` file to `/api/ingest/sourcemaps` with the server
token, the public path of its bundle and the release, then delete the maps. See
[Source maps](../api.md#source-maps) for a `curl` loop.

**When it works:** the maps are listed in the project's **Settings** under **Source
maps**, and the next browser error shows the original source. Errors that came in before
the upload keep their minified frames.

## 3. Report the maintenance

When the deploy takes the app down, tell Lumberjack. While the
[maintenance window](../health.md#maintenance-windows) is open, health checks send no
alerts and the down time does not count against uptime.

**Laravel SDK.** `artisan down` and `artisan up` open and close the window, with no
setup. Set `expected_minutes` under `maintenance` in the package's config to say how
long it takes. See
[Maintenance](https://exeque.github.io/laravel-lumberjack/docs/recording#maintenance).

**Anything else.** Send a `maintenance` event with `state` `start` before the deploy, and
one with `end` after it. See [Maintenance events](../api.md#maintenance-events).

Only an `end` event, or **End now** on the **Health** page, closes the window. Still open
past its expected end, it sends the **Maintenance overrun** alert. See
[Get told when production breaks](./alerting.md).

**When it works:** the window shows as a band on the project's charts and on the
**Releases** page.

## 4. Compare it with the release before

Open **Releases** in the sidebar. Every release seen in the period is a row, newest
first.

![The Releases page](../screenshots/releases.png)

Read the new release's row against the one below it:

- **5xx rate** and **p95** have an arrow: red and up when worse, green and down when
  better.
- **New issues**: errors that first appeared on this release.
- **Regressions**: resolved issues that came back on this release.
- **Errors**: all error occurrences on this release.

Pick the environment at the top, and a short period such as 1 h to keep to the hours
around the deploy.

## 5. Look at what it broke

The release's detail lists **New issues in** that release. **Issues last seen in this
release** opens the issues list filtered to it; the list's **Release** filter does the
same for any of the 20 newest releases.

**When it went well:** no new issues, no regressions, and green or no arrows next to
the 5xx rate and p95.

## 6. Get told about new releases

To get a notification when a project reports a new release, click your name at the bottom
of the sidebar, then **Profile and settings**, then **Notifications**, and turn on the
**Releases** row.

New issues and regressions reach the team's channels through the **New issue** and
**Regression** alert rules. See [Get told when production breaks](./alerting.md).

## 7. Show the release on a status screen

On a [status screen](./status-tv.md):

- A **Stat** widget with **Current release** shows the release running now, how long it
  has been out, and its 5xx rate against the release before.
- Every data widget over a period has a **Release** setting under **Scope**: **All**,
  **Current only** or **Previous**, so two charts side by side can compare them.
- A **Chart** can **Split by** **Release**, one line per release.

![The current release as a stat](../screenshots/widget-stat-release.png)

## Reference

- [Releases](../releases.md): every column of the Releases page.
- [Issues](../issues.md): statuses, regressions and the issue page.
- [Widgets](../widgets.md): the Stat and Chart widgets and their scope.
