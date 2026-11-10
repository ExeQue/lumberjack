<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminAuditController;
use App\Domains\Administration\Http\Controllers\AdminTeamController;
use App\Domains\Administration\Http\Controllers\AdminUpdateCheckController;
use App\Domains\Administration\Http\Controllers\AdminUserController;
use App\Domains\Administration\Http\Middleware\EnsureUserIsAdmin;
use App\Domains\Retention\Http\Controllers\RetentionController;
use Illuminate\Support\Facades\Route;

/*
 * System administration: every team and user on this Lumberjack. Loaded inside the auth group.
 */

Route::prefix('admin')->middleware(EnsureUserIsAdmin::class)->group(function () {
    Route::post('update-check', AdminUpdateCheckController::class)->name('admin.update-check');
    Route::get('teams', [AdminTeamController::class, 'index'])->name('admin.teams.index');
    Route::post('teams', [AdminTeamController::class, 'store'])->name('admin.teams.store');
    Route::post('teams/{team}/open', [AdminTeamController::class, 'open'])->name('admin.teams.open');
    Route::delete('teams/{team}', [AdminTeamController::class, 'destroy'])->name('admin.teams.destroy');
    Route::get('users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::post('users/invite', [AdminUserController::class, 'invite'])->name('admin.users.invite');
    Route::put('users/{user}/suspended', [AdminUserController::class, 'suspend'])->name('admin.users.suspend');
    Route::put('users/{user}/admin', [AdminUserController::class, 'admin'])->name('admin.users.admin');
    Route::put('users/{user}/password-change', [AdminUserController::class, 'requirePasswordChange'])->name('admin.users.password-change');
    Route::post('users/{user}/password-reset', [AdminUserController::class, 'sendReset'])->name('admin.users.reset');
    Route::get('audit', AdminAuditController::class)->name('admin.audit');
    Route::get('retention', [RetentionController::class, 'show'])->name('admin.retention');
    Route::put('retention', [RetentionController::class, 'application'])->name('admin.retention.update');
});
