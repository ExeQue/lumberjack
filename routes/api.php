<?php

declare(strict_types=1);

use App\Domains\Health\Http\Controllers\HealthPingController;
use App\Domains\Ingest\Http\Controllers\IngestController;
use App\Domains\Ingest\Http\Middleware\AuthenticateProjectToken;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateProjectToken::class, 'throttle:ingest'])->group(function () {
    Route::post('ingest', [IngestController::class, 'store'])->name('ingest.store');
    Route::post('ingest/heartbeat', [IngestController::class, 'heartbeat'])->name('ingest.heartbeat');
});

Route::post('ingest/browser', [IngestController::class, 'browser'])->middleware('throttle:ingest-browser')->name('ingest.browser');
Route::options('ingest/browser', [IngestController::class, 'browserPreflight'])->name('ingest.browser.preflight');

// A heartbeat check's secret URL: cron jobs and backups call it when they are done.
Route::match(['get', 'post'], 'health/ping/{token}', HealthPingController::class)
    ->where('token', '[a-f0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('health.ping');
