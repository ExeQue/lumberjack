<?php

declare(strict_types=1);

use App\Domains\Health\Http\Controllers\HealthPingController;
use App\Domains\Health\Http\Controllers\HealthStatesController;
use App\Domains\Ingest\Http\Controllers\IngestController;
use App\Domains\Ingest\Http\Middleware\AuthenticateProjectToken;
use Illuminate\Support\Facades\Route;

// Everything an app calls with its project's server token.
Route::middleware(AuthenticateProjectToken::class)->group(function () {
    Route::middleware('throttle:ingest')->group(function () {
        Route::post('ingest', [IngestController::class, 'store'])->name('ingest.store');
        Route::post('ingest/heartbeat', [IngestController::class, 'heartbeat'])->name('ingest.heartbeat');
    });

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
