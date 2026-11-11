<?php

declare(strict_types=1);

use App\Domains\Overview\Http\Controllers\OverviewController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Middleware\FollowProjectTeam;
use Illuminate\Support\Facades\Route;

/*
 * The projects list, and every page of one project, each area in a file of its own under projects/. Loaded inside the auth group.
 */

Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

// Every project page needs at least view on the project, whatever its controller checks too.
Route::prefix('projects/{project}')->scopeBindings()->middleware(['can:view,project', FollowProjectTeam::class])->group(function () {
    Route::get('/', OverviewController::class)->name('projects.show');
    Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');

    require __DIR__.'/projects/issues.php';
    require __DIR__.'/projects/performance.php';
    require __DIR__.'/projects/operations.php';
    Route::prefix('connectors')->group(function () {
        require __DIR__.'/projects/connectors/github.php';
        require __DIR__.'/projects/connectors/mosparo.php';
        require __DIR__.'/projects/connectors/umami.php';
    });
    require __DIR__.'/projects/data-sources.php';
    require __DIR__.'/projects/settings.php';
});
