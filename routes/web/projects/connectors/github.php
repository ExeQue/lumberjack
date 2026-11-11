<?php

declare(strict_types=1);

use App\Domains\Connectors\GitHub\Http\Controllers\GitHubConnectionController;
use App\Domains\Connectors\GitHub\Http\Controllers\GitHubController;
use Illuminate\Support\Facades\Route;

/*
 * The GitHub connector: its page and connecting a repository. See docs/connectors/github.md. Loaded inside the project group.
 */

Route::post('github/connection', [GitHubConnectionController::class, 'store'])->name('connectors.github.connection.store');
Route::get('github/{dataSourceConnection?}', GitHubController::class)->name('connectors.github.index');
