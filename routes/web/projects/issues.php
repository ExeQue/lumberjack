<?php

declare(strict_types=1);

use App\Domains\Issues\Http\Controllers\IssueActionController;
use App\Domains\Issues\Http\Controllers\IssueController;
use App\Domains\Issues\Http\Controllers\WatchingController;
use Illuminate\Support\Facades\Route;

/*
 * A project's issues and what is done to them. Loaded inside the project group.
 */

Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
Route::post('issues/status', [IssueActionController::class, 'bulkStatus'])->name('issues.bulk-status');
Route::post('issues/assignee', [IssueActionController::class, 'bulkAssign'])->name('issues.bulk-assign');
Route::post('issues/labels', [IssueActionController::class, 'bulkLabels'])->name('issues.bulk-labels');
Route::post('issues/watch', [WatchingController::class, 'bulk'])->name('issues.bulk-watch');
Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
Route::put('issues/{issue}/status', [IssueActionController::class, 'status'])->name('issues.status');
Route::put('issues/{issue}/assignee', [IssueActionController::class, 'assign'])->name('issues.assign');
Route::put('issues/{issue}/labels', [IssueActionController::class, 'labels'])->name('issues.labels');
Route::put('issues/{issue}/watch', [WatchingController::class, 'update'])->name('issues.watch');
Route::post('issues/{issue}/notes', [IssueActionController::class, 'note'])->name('issues.notes.store');
Route::post('issues/{issue}/repo-issue', [IssueActionController::class, 'repoIssue'])->name('issues.repo-issue');
