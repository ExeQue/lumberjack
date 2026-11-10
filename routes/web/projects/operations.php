<?php

declare(strict_types=1);

use App\Domains\Alerts\Http\Controllers\QueueAlertThresholdController;
use App\Domains\Commands\Http\Controllers\CommandController;
use App\Domains\Health\Http\Controllers\HealthController;
use App\Domains\Jobs\Http\Controllers\JobController;
use App\Domains\Logs\Http\Controllers\LogController;
use App\Domains\Schedule\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
 * A project's jobs, queue alert, schedule, commands, logs and health checks. Loaded inside the project group.
 */

Route::get('jobs', JobController::class)->name('jobs.index');
Route::put('jobs/queue-alert', [QueueAlertThresholdController::class, 'update'])->name('queues.alert');
Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
Route::get('commands', CommandController::class)->name('commands.index');
Route::delete('schedule/{scheduledTask}', [ScheduleController::class, 'destroy'])->name('schedule.destroy');
Route::get('logs', LogController::class)->name('logs.index');
Route::get('health', [HealthController::class, 'index'])->name('health.index');
Route::post('health', [HealthController::class, 'store'])->name('health.store');
Route::put('health/{healthCheck}', [HealthController::class, 'update'])->name('health.update');
Route::put('health/{healthCheck}/archive', [HealthController::class, 'archive'])->name('health.archive');
Route::delete('health/{healthCheck}', [HealthController::class, 'destroy'])->name('health.destroy');
