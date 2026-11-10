<?php

declare(strict_types=1);

use App\Domains\Performance\Http\Controllers\BrowserController;
use App\Domains\Performance\Http\Controllers\QueryController;
use App\Domains\Performance\Http\Controllers\RouteController;
use App\Domains\Performance\Http\Controllers\TraceController;
use App\Domains\Releases\Http\Controllers\ReleaseController;
use Illuminate\Support\Facades\Route;

/*
 * A project's requests, queries, browser pages, traces and releases. Loaded inside the project group.
 */

Route::get('requests', RouteController::class)->name('routes.index');
Route::get('database', QueryController::class)->name('queries.index');
Route::get('browser', BrowserController::class)->name('browser.index');
Route::get('releases', ReleaseController::class)->name('releases.index');
Route::get('traces/{trace}', TraceController::class)->where('trace', '[A-Za-z0-9._:-]{1,64}')->name('traces.show');
