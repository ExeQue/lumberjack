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

| Account             | Team           | Role                               |
| ------------------- | -------------- | ---------------------------------- |
| `admin@lumberjack`  | Local Team     | Owner, and administers Lumberjack  |
| `sofie@lumberjack`  | Local Team     | Admin                              |
| `jonas@lumberjack`  | Local Team     | Member                             |
| `maja@lumberjack`   | Local Team     | Admin                              |
| `oskar@lumberjack`  | Local Team     | Member                             |
| `ida@lumberjack`    | Local Team     | Member                             |
| `eve@lumberjack`    | Acme Agency    | Owner                              |
| `noah@lumberjack`   | Acme Agency    | Member                             |
| `clara@lumberjack`  | Acme Agency    | Admin                              |
| `freja@lumberjack`  | Northwind Labs | Owner                              |
| `emil@lumberjack`   | Northwind Labs | Admin                              |
| `asta@lumberjack`   | Northwind Labs | Member                             |

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

| Variable         | Default                     | Used for                                  |
| ---------------- | --------------------------- | ----------------------------------------- |
| `LUMBERJACK_URL` | `https://lumberjack.test`   | The Lumberjack to open                    |
| `DOCS_EMAIL`     | `admin@lumberjack`          | Sign-in email                             |
| `DOCS_PASSWORD`  | `password`                  | Sign-in password                          |
| `DOCS_PROJECT`   | `Storefront`                | The project to show                       |
| `CHROME_PATH`    | the Chrome Puppeteer downloaded | A Chrome or Chromium to use instead   |

```bash
CHROME_PATH=/usr/bin/chromium npm run docs:screenshots
```

Reduce the images to 256 colors with ImageMagick before you commit them:

```bash
for f in docs/screenshots/*.png; do convert "$f" -dither None -colors 256 "PNG8:$f"; done
```

## Docs site

The docs are built with VitePress:

```bash
npm run docs:dev      # local preview
npm run docs:build
```
