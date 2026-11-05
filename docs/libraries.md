# Libraries

Your apps send data to Lumberjack through a library. Each one links to its own docs.

| Library                                                                     | For                                 |
| --------------------------------------------------------------------------- | ----------------------------------- |
| [`exeque/laravel-lumberjack`](https://github.com/ExeQue/laravel-lumberjack) | Laravel 12 and 13, PHP 8.3 or newer |

The [browser script](./projects.md#browser-script) is served by Lumberjack itself and needs no
library. To send data from anything else, use the [ingest API](./api.md).

## Laravel

```bash
composer require exeque/laravel-lumberjack
php artisan lumberjack:install   # keys, browser script, health checks, how to run the agent
php artisan lumberjack:agent     # under Supervisor or systemd
php artisan lumberjack:test      # check it end to end
```

A new project opens on its **Installation** settings with the `.env` values to set. The
server token is shown only once. See [Projects](./projects.md).
