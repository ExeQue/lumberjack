<?php

declare(strict_types=1);

use App\Domains\Ingest\Http\Controllers\IngestController;
use App\Domains\Ingest\Http\Middleware\AuthenticateProjectToken;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateProjectToken::class, 'throttle:ingest'])->group(function () {
    Route::post('ingest', [IngestController::class, 'store'])->name('ingest.store');
    Route::post('ingest/heartbeat', [IngestController::class, 'heartbeat'])->name('ingest.heartbeat');
});

Route::post('ingest/browser', [IngestController::class, 'browser'])->middleware('throttle:ingest-browser')->name('ingest.browser');
Route::options('ingest/browser', [IngestController::class, 'browserPreflight'])->name('ingest.browser.preflight');
