<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,

    // Domains
    App\Domains\Ingest\Providers\IngestServiceProvider::class,
    App\Domains\Alerts\Providers\AlertsServiceProvider::class,
    App\Domains\Live\Providers\LiveServiceProvider::class,
    App\Domains\Issues\Providers\IssuesServiceProvider::class,
    App\Domains\Mcp\Providers\McpServiceProvider::class,
    App\Domains\Metrics\Providers\MetricsServiceProvider::class,
    App\Domains\Screens\Providers\ScreensServiceProvider::class,
    App\Domains\Notifications\Providers\NotificationsServiceProvider::class,
];
