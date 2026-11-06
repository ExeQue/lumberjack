# Installing from source

Lumberjack is a Laravel 13 application, and without Docker it runs like any other. The
[Docker image](./docker.md) does the same as these steps, so read this when you would rather
run it on a server you already manage.

It needs:

- PHP 8.5 with the `pdo_pgsql`, `redis`, `pcntl`, `intl`, `gd`, `zip` and `bcmath` extensions
- Composer, and Node 22 to build the frontend
- PostgreSQL and Redis
- A web server with PHP, such as FrankenPHP, Caddy or nginx with PHP-FPM

## 1. Get the code

Check out the latest [release](https://github.com/ExeQue/lumberjack/releases), not `main`:

```bash
git clone https://github.com/ExeQue/lumberjack.git
cd lumberjack
git checkout "$(git describe --tags --abbrev=0)"
```

## 2. Install it

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
```

## 3. Configure it

Change these in `.env`. Everything else can stay as it is.

| Variable                                               | Set to                                                                       |
| ------------------------------------------------------ | ---------------------------------------------------------------------------- |
| `APP_ENV`, `APP_DEBUG`                                 | `production`, `false`                                                        |
| `APP_URL`                                              | The address people open Lumberjack on, e.g. `https://lumberjack.example.com` |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Your PostgreSQL database                                                     |
| `REDIS_HOST`, `REDIS_PASSWORD`                         | Your Redis                                                                   |
| `BROADCAST_CONNECTION`                                 | `reverb`                                                                     |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Any values of your own, e.g. from `openssl rand -hex 16`                     |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`          | `127.0.0.1`, `8081`, `http`: where the app reaches Reverb                    |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`             | `127.0.0.1`, `8081`: where Reverb listens                                    |
| `LUMBERJACK_SETUP_TOKEN`                               | A token that locks first-run setup, e.g. from `openssl rand -hex 24`         |
| `MAIL_*`                                               | Your SMTP server, see [Mail](./administration#mail)                          |

Then prepare the database and the caches:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Run `php artisan optimize` again after every change to `.env`.

## 4. Serve it

Point the web server's document root at `public/`, and send `/app/*` on to Reverb at
`127.0.0.1:8081` with WebSocket upgrades: the browser opens its live updates on the same
address as the pages. With Caddy:

```
lumberjack.example.com {
    root * /var/www/lumberjack/public
    reverse_proxy /app/* 127.0.0.1:8081
    php_fastcgi unix//run/php/php8.5-fpm.sock
    file_server
}
```

The web server's user has to be able to write `storage/` and `bootstrap/cache/`.

## 5. Run the background processes

Three processes run beside the web server, all from the project folder and as the same user.
Keep them running with Supervisor or systemd:

| Process   | Command                     | Does                                                                     |
| --------- | --------------------------- | ------------------------------------------------------------------------ |
| Horizon   | `php artisan horizon`       | Ingests telemetry and sends alerts                                       |
| Scheduler | `php artisan schedule:work` | Health checks, data sources, missed scheduled tasks, digests and pruning |
| Reverb    | `php artisan reverb:start`  | Live updates in the browser                                              |

## 6. Set it up

Open `APP_URL/setup`, enter the setup token and create the first administrator. See
[Setup and system administration](./administration.md).

## Updating

```bash
git fetch --tags
git checkout "$(git describe --tags "$(git rev-list --tags --max-count=1)")"
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate
php artisan reverb:restart
```

Supervisor or systemd starts Horizon and Reverb again with the new code; restart the
scheduler too.
