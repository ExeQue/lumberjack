<?php

declare(strict_types=1);

use App\Domains\Connectors\Http\Controllers\ConnectorController;
use Illuminate\Support\Facades\Route;

/*
 * The Connectors page: every connector, and what the project has connected of each. See docs/connectors/index.md. Loaded inside the project group.
 */

Route::get('/', ConnectorController::class)->name('connectors.index');
