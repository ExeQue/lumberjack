<?php

declare(strict_types=1);

use App\Domains\DataSources\Http\Controllers\DataSourceConnectionController;
use App\Domains\DataSources\Http\Controllers\DataSourceController;
use Illuminate\Support\Facades\Route;

/*
 * A project's data sources and the apps it pulls them from. See docs/data-sources.md. Loaded inside the project group.
 */

Route::get('data-sources', [DataSourceController::class, 'index'])->name('data-sources.index');
Route::post('data-sources', [DataSourceController::class, 'store'])->name('data-sources.store');
Route::post('data-sources/connections', [DataSourceConnectionController::class, 'store'])->name('data-source-connections.store');
Route::patch('data-sources/connections/{dataSourceConnection}', [DataSourceConnectionController::class, 'update'])->name('data-source-connections.update');
Route::post('data-sources/connections/{dataSourceConnection}/pull-token', [DataSourceConnectionController::class, 'rotate'])->name('data-source-connections.rotate');
Route::delete('data-sources/connections/{dataSourceConnection}', [DataSourceConnectionController::class, 'destroy'])->name('data-source-connections.destroy');
Route::put('data-sources/{dataSource}', [DataSourceController::class, 'update'])->name('data-sources.update');
Route::post('data-sources/{dataSource}/fetch', [DataSourceController::class, 'fetch'])->middleware('throttle:10,1')->name('data-sources.fetch');
Route::delete('data-sources/{dataSource}', [DataSourceController::class, 'destroy'])->name('data-sources.destroy');
