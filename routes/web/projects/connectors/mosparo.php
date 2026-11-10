<?php

declare(strict_types=1);

use App\Domains\Connectors\Mosparo\Http\Controllers\MosparoConnectionController;
use App\Domains\Connectors\Mosparo\Http\Controllers\MosparoController;
use Illuminate\Support\Facades\Route;

/*
 * The mosparo connector: its page and connecting a mosparo project. See docs/mosparo.md. Loaded inside the project group.
 */

Route::post('mosparo/connection', [MosparoConnectionController::class, 'store'])->name('connectors.mosparo.connection.store');
Route::get('mosparo/{dataSourceConnection?}', MosparoController::class)->name('connectors.mosparo.index');
