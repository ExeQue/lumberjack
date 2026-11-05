# Libraries

Lumberjack is not tied to a framework. Your apps send data through a library where one
exists, and through the [ingest API](./api.md) from anything else. Each library links to its own
docs.

| Library                                                                     | For                                 |
| --------------------------------------------------------------------------- | ----------------------------------- |
| [`exeque/laravel-lumberjack`](https://exeque.github.io/laravel-lumberjack/) | Laravel 12 and 13, PHP 8.3 or newer |

The [browser script](./projects.md#browser-script) is served by Lumberjack itself and needs no
library.

Libraries hand their data to a local agent, which sends it on with retry and a disk spool:
[`exeque/lumberjack-agent`](https://github.com/ExeQue/lumberjack-agent). It also runs on its own,
for an app without a library, and its README describes the line protocol for writing one.

## Laravel

```bash
composer require exeque/laravel-lumberjack
php artisan lumberjack:install   # keys, browser script, health checks, how to run the agent
php artisan lumberjack:agent     # under Supervisor or systemd
php artisan lumberjack:test      # check it end to end
```

A new project opens on its **Installation** settings with the `.env` values to set. The
server token is shown only once. See [Projects](./projects.md).
