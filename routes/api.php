<?php

declare(strict_types=1);

use App\Domains\Activity\Http\Controllers\ActivityActorController;
use App\Domains\Health\Http\Controllers\HealthPingController;
use App\Domains\Health\Http\Controllers\HealthStatesController;
use App\Domains\Ingest\Http\Controllers\IngestController;
use App\Domains\Ingest\Http\Middleware\AuthenticateProjectToken;
use App\Domains\Metrics\Http\Controllers\MetricsApiController;
use App\Domains\Metrics\Http\Middleware\AuthenticateMetricsCredential;
use App\Domains\OpenTelemetry\Http\Controllers\OpenTelemetryController;
use App\Domains\SourceMaps\Http\Controllers\SourceMapController;
use Illuminate\Support\Facades\Route;

// Everything an app calls with its project's server token.
Route::middleware(AuthenticateProjectToken::class)->group(function () {
    Route::middleware('throttle:ingest')->group(function () {
        Route::post('ingest', [IngestController::class, 'store'])->name('ingest.store');
        Route::post('ingest/heartbeat', [IngestController::class, 'heartbeat'])->name('ingest.heartbeat');

        // OTLP/HTTP from an OpenTelemetry SDK or collector, with this as its base endpoint. See docs/opentelemetry.md.
        Route::prefix('v1/opentelemetry/v1')->name('opentelemetry.')->controller(OpenTelemetryController::class)->group(function () {
            Route::post('traces', 'traces')->name('traces');
            Route::post('logs', 'logs')->name('logs');
            Route::post('metrics', 'metrics')->name('metrics');
        });
    });

    // A build's source maps, so browser errors show the original source. See docs/api.md.
    Route::post('ingest/sourcemaps', [SourceMapController::class, 'store'])->middleware('throttle:sourcemaps')->name('ingest.sourcemaps');

    // The app forgets one actor's events, such as when the account behind it is deleted. See docs/events.md.
    Route::delete('events/actor', [ActivityActorController::class, 'destroy'])->middleware('throttle:60,1')->name('events.actor.forget');

    // The app reads its health check states back, e.g. when its agent is not running.
    Route::get('health', HealthStatesController::class)->middleware('throttle:health-states')->name('health.states');
});

Route::post('ingest/browser', [IngestController::class, 'browser'])->middleware('throttle:ingest-browser')->name('ingest.browser');
Route::options('ingest/browser', [IngestController::class, 'browserPreflight'])->name('ingest.browser.preflight');

// A heartbeat check's secret URL: cron jobs and backups call it when they are done.
Route::match(['get', 'post'], 'health/ping/{token}', HealthPingController::class)
    ->where('token', '[a-f0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('health.ping');

// The read-only metrics API (Grafana, Prometheus, the status screen): with a team token,
// or under a project's public stats key without one. See docs/metrics-api.md.
$metrics = function (): void {
    Route::get('projects', [MetricsApiController::class, 'projects'])->name('projects');
    Route::get('metrics', [MetricsApiController::class, 'prometheus'])->name('prometheus');

    Route::prefix('projects/{project}')->group(function () {
        Route::get('summary', [MetricsApiController::class, 'summary'])->name('summary');
        Route::get('series/{metric}', [MetricsApiController::class, 'series'])->name('series');
        Route::get('routes', [MetricsApiController::class, 'routes'])->name('routes');
        Route::get('issues', [MetricsApiController::class, 'issues'])->name('issues');
        Route::get('jobs', [MetricsApiController::class, 'jobs'])->name('jobs');
        Route::get('queues', [MetricsApiController::class, 'queues'])->name('queues');
        Route::get('hosts', [MetricsApiController::class, 'hosts'])->name('hosts');
        Route::get('releases', [MetricsApiController::class, 'releases'])->name('releases');
    });
};

Route::prefix('v1')->name('metrics.')->middleware([AuthenticateMetricsCredential::class, 'throttle:metrics'])->group($metrics);
Route::prefix('v1/public/{key}')->name('metrics.public.')->where(['key' => 'ps_[a-z0-9]{40}'])
    ->middleware([AuthenticateMetricsCredential::class, 'throttle:metrics'])->group($metrics);
