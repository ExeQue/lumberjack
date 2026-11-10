<?php

declare(strict_types=1);

use App\Domains\Connectors\Umami\Http\Controllers\UmamiConnectionController;
use App\Domains\Connectors\Umami\Http\Controllers\UmamiController;
use Illuminate\Support\Facades\Route;

/*
 * The Umami connector: its page, connecting websites, UTM names and annotations. See docs/connectors/umami.md. Loaded inside the project group.
 */

Route::get('umami/{dataSourceConnection?}', UmamiController::class)->name('connectors.umami.index');
Route::post('umami/websites', [UmamiConnectionController::class, 'websites'])->middleware('throttle:20,1')->name('connectors.umami.websites');
Route::post('umami/connection', [UmamiConnectionController::class, 'store'])->name('connectors.umami.connection.store');
Route::get('umami/{dataSourceConnection}/websites', [UmamiConnectionController::class, 'savedWebsites'])->middleware('throttle:20,1')->name('connectors.umami.connection.websites');
Route::post('umami/{dataSourceConnection}/websites', [UmamiConnectionController::class, 'add'])->name('connectors.umami.connection.add');
Route::put('umami/{dataSourceConnection}', [UmamiConnectionController::class, 'update'])->name('connectors.umami.connection.update');
Route::put('umami/{dataSourceConnection}/utm-labels', [UmamiConnectionController::class, 'label'])->name('connectors.umami.utm-labels.update');
Route::post('umami/{dataSourceConnection}/annotations', [UmamiConnectionController::class, 'annotate'])->name('connectors.umami.annotations.store');
Route::delete('umami/{dataSourceConnection}/annotations/{annotation}', [UmamiConnectionController::class, 'unannotate'])->where('annotation', '[A-Za-z0-9-]{1,64}')->name('connectors.umami.annotations.destroy');
