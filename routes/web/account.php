<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\OnboardingController;
use App\Domains\Notifications\Http\Controllers\NotificationCenterController;
use App\Domains\Shared\Http\Controllers\AboutController;
use Illuminate\Support\Facades\Route;

/*
 * The signed-in user's own pages: the dashboard redirect, about, onboarding and notifications. Loaded inside the auth group.
 */

Route::get('dashboard', fn () => redirect()->route('projects.index'))->name('dashboard');
Route::get('about', AboutController::class)->name('about');

// The first-login onboarding, also opened again from the notification settings. See docs/teams.md.
Route::get('onboarding/{step?}', [OnboardingController::class, 'show'])->whereIn('step', range(1, OnboardingController::STEPS))->name('onboarding.show');
Route::post('onboarding', [OnboardingController::class, 'complete'])->name('onboarding.complete');

Route::get('notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
Route::get('notifications/recent', [NotificationCenterController::class, 'recent'])->name('notifications.recent');
Route::post('notifications/read', [NotificationCenterController::class, 'readAll'])->name('notifications.read-all');
Route::put('notifications/{notification}', [NotificationCenterController::class, 'update'])->whereUuid('notification')->name('notifications.mark');
