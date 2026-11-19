# Development

Lumberjack is a Laravel 13 application with an Inertia and React frontend. It needs PHP 8.5,
Node 22, Postgres and Redis.

## Run it from source

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
```

## Profile requests

[Monocle](https://github.com/ExeQue/laravel-monocle) profiles every request, job and command
while `APP_DEBUG` is on.

- The round button in the bottom right corner of a page opens its captures.
- `/_monocle` is the dashboard.
- For a coding agent, add Monocle's MCP server to your client: `php artisan mcp:start monocle`.

## Run the checks

These are the checks CI runs:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --parallel
npm run check
npm run types:check
```

## Demo data

`php artisan migrate --seed` or `php artisan migrate:fresh --seed` fills a fresh database in
about half a minute. The data is placed relative to the minute you seed, and is the same on
every run.

To add only the showcase to a database you already use:

```bash
php artisan db:seed --class=ShowcaseSeeder
```

This adds only the missing showcase people and projects. The seeded health checks point at
placeholder hosts, so they turn red after a couple of hours of the scheduler running them.

### Accounts

Every password is `password`.

| Account            | Team           | Role                              |
| ------------------ | -------------- | --------------------------------- |
| `admin@lumberjack` | Local Team     | Owner, and administers Lumberjack |
| `sofie@lumberjack` | Local Team     | Admin                             |
| `jonas@lumberjack` | Local Team     | Member                            |
| `maja@lumberjack`  | Local Team     | Admin                             |
| `oskar@lumberjack` | Local Team     | Member                            |
| `ida@lumberjack`   | Local Team     | Member                            |
| `eve@lumberjack`   | Acme Agency    | Owner                             |
| `noah@lumberjack`  | Acme Agency    | Member                            |
| `clara@lumberjack` | Acme Agency    | Admin                             |
| `freja@lumberjack` | Northwind Labs | Owner                             |
| `emil@lumberjack`  | Northwind Labs | Admin                             |
| `asta@lumberjack`  | Northwind Labs | Member                            |

### Projects

- **Demo** in Local Team is for the SDK workbench. Its server token, public key and three
  MCP agent tokens are fixed, so a fresh seed keeps the workbench and local MCP clients
  working; the seeder prints them. Acme Agency members cannot see it.
- The showcase has six projects with a month of data:
    - Local Team: **Storefront** (a webshop on `shop.example.com`), **Orders API** (an API on
      `api.example.com`), **Marketing Site** and **Fulfillment Worker** (queues and scheduled
      tasks, no web traffic).
    - Acme Agency: **Acme Booking**.
    - Northwind Labs: **Customer Portal**.

The showcase contains:

- releases over 30 days, including a bad Storefront release that broke checkout and was
  rolled back;
- daily and weekly traffic, with sampled requests, queries, spans and Web Vitals, an N+1 on
  the product page and a slow sales report;
- about 30 issues in every status, with assignees, labels, notes, watchers and one that is
  escalating;
- a week of jobs, queue sizes with one backlog, failed and missed scheduled tasks, and
  commands;
- two weeks of health results with outages, one check recovering, one down and one stale;
- agents with a day of host samples;
- log lines per channel;
- alert channels with placeholder targets, and what they sent;
- status screens from the templates, and a TV showing two of them;
- retention settings for the application, a team and a project.

## Screenshots

The images in `docs/screenshots` are taken from a running Lumberjack with Puppeteer:

```bash
npx puppeteer browsers install chrome   # once
npm run docs:screenshots                # every screenshot
npm run docs:screenshots -- issues jobs # only these
```

The script only opens pages and dialogs. It reads these environment variables:

| Variable          | Default                                | Used for                                                                                      |
| ----------------- | -------------------------------------- | --------------------------------------------------------------------------------------------- |
| `LUMBERJACK_URL`  | `https://lumberjack.test`              | The Lumberjack to open                                                                        |
| `DOCS_EMAIL`      | `admin@lumberjack`                     | Sign-in email                                                                                 |
| `DOCS_PASSWORD`   | `password`                             | Sign-in password                                                                              |
| `DOCS_PROJECT`    | `Storefront`                           | The project to show                                                                           |
| `DOCS_AI_PROJECT` | `Customer Portal`, else `DOCS_PROJECT` | The project whose AI usage to show, by name or `/projects/<uuid>` (it may be in another team) |
| `CHROME_PATH`     | the Chrome Puppeteer downloaded        | A Chrome or Chromium to use instead                                                           |

```bash
CHROME_PATH=/usr/bin/chromium npm run docs:screenshots
```

Reduce the images to 256 colors with ImageMagick before you commit them:

```bash
for f in docs/screenshots/*.png; do convert "$f" -dither None -colors 256 "PNG8:$f"; done
```

## Promo video

`npm run docs:video` makes a 90-second film of Lumberjack: the app in a browser window, with
titles, zooms, highlights and transitions. It needs `ffmpeg` with libx264.

```bash
php artisan migrate:fresh --seed   # the film is cut for the showcase data
npm run docs:video                 # storage/app/promo/lumberjack-promo.mp4
```

It runs in two steps, and each can run alone:

1. `node scripts/promo/capture.mjs` signs in and takes sharp stills of the pages, and notes
   where in them the film points (`storage/app/promo/shots`). It only opens pages and
   dialogs. Seed right before it: the seeded health checks go stale within minutes.
2. `node scripts/promo/render.mjs` draws the film frame by frame over those stills and
   encodes it at 1920 x 1080 and 60 frames a second. Lumberjack does not have to run for it.

The film itself is `scripts/promo/stage.js` (texts, timing, camera) and
`scripts/promo/stage.css` (its look). While you work on it:

```bash
node scripts/promo/render.mjs --at 12,28.5,37   # single frames, as storage/app/promo/at-<seconds>.jpg
node scripts/promo/render.mjs --from 31 --to 41 --fps 30 --out /tmp/part.mp4
```

Or open `storage/app/promo/stage.html#play` in a browser after one render to watch it play;
`#play=31` starts at 31 seconds.

Capturing reads the same environment variables as the screenshots, with `maja@lumberjack` as
the default `DOCS_EMAIL`. Rendering reads `CHROME_PATH` and:

| Variable      | Default              | Used for                  |
| ------------- | -------------------- | ------------------------- |
| `FFMPEG_PATH` | `ffmpeg` on the PATH | The ffmpeg to encode with |

In the film the demo team is called Nordic Goods. Only the stills say so; nothing is
changed in the database.

## Docs site

The docs are built with VitePress:

```bash
npm run docs:dev      # local preview
npm run docs:build
```
