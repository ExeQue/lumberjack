<?php

declare(strict_types=1);

use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Issues\Http\Controllers\LabelController;
use App\Domains\Logs\Http\Controllers\LogChannelController;
use App\Domains\Projects\Http\Controllers\ProjectAgentController;
use App\Domains\Projects\Http\Controllers\ProjectSettingsController;
use App\Domains\Repositories\Http\Controllers\RepositoryController;
use App\Domains\Retention\Http\Controllers\RetentionController;
use App\Domains\Search\Http\Controllers\SearchController;
use App\Domains\SourceMaps\Http\Controllers\SourceMapController;
use Illuminate\Support\Facades\Route;

/*
 * A project's settings, its search, and its alert rules. Loaded inside the project group.
 */

Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('projects.search');

Route::get('settings', [ProjectSettingsController::class, 'edit'])->name('projects.settings');
Route::put('settings', [ProjectSettingsController::class, 'update'])->name('projects.settings.update');
Route::post('settings/server-token', [ProjectSettingsController::class, 'rotateToken'])->name('projects.rotate-token');
Route::post('settings/public-key', [ProjectSettingsController::class, 'rotatePublicKey'])->name('projects.rotate-public-key');
Route::put('settings/public-stats', [ProjectSettingsController::class, 'updatePublicStats'])->name('projects.public-stats');
Route::post('settings/public-stats/key', [ProjectSettingsController::class, 'rotateStatsKey'])->name('projects.rotate-stats-key');
Route::post('settings/log-channels', [LogChannelController::class, 'store'])->name('log-channels.store');
Route::delete('settings/log-channels/{logChannel}', [LogChannelController::class, 'destroy'])->name('log-channels.destroy');
Route::delete('settings/source-maps/{sourceMap}', [SourceMapController::class, 'destroy'])->name('source-maps.destroy');
Route::delete('settings/agents/{agent}', [ProjectAgentController::class, 'destroy'])->name('agents.destroy');
Route::put('settings/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
Route::delete('settings/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
Route::put('settings/retention', [RetentionController::class, 'project'])->name('projects.retention');
Route::post('settings/alert-rules/{type}/{topic?}', [AlertController::class, 'customizeRule'])->name('alerts.rules.customize');
Route::put('settings/alert-rules/{rule}/add-ons', [AlertController::class, 'updateAddOns'])->withoutScopedBindings()->name('alerts.rules.add-ons');
Route::put('settings/repository', [RepositoryController::class, 'update'])->name('repository.update');
Route::post('settings/repository/test', [RepositoryController::class, 'test'])->name('repository.test');
Route::delete('settings/repository', [RepositoryController::class, 'destroy'])->name('repository.destroy');
