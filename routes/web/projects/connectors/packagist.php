<?php

declare(strict_types=1);

use App\Domains\Connectors\Packagist\Http\Controllers\PackagistConnectionController;
use App\Domains\Connectors\Packagist\Http\Controllers\PackagistController;
use Illuminate\Support\Facades\Route;

/*
 * The Packagist connector: its page, finding a package and connecting it. See docs/connectors/packagist.md. Loaded inside the project group.
 */

Route::get('packagist/search', [PackagistConnectionController::class, 'search'])->middleware('throttle:60,1')->name('connectors.packagist.search');
Route::post('packagist/connection', [PackagistConnectionController::class, 'store'])->name('connectors.packagist.connection.store');
Route::get('packagist/{dataSourceConnection?}', PackagistController::class)->name('connectors.packagist.index');
