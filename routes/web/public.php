<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\SetupController;
use App\Domains\Administration\Http\Middleware\EnsureSetupIsOpen;
use App\Domains\Issues\Http\Controllers\WatchingController;
use App\Domains\Screens\Http\Controllers\ScreenPreviewController;
use App\Domains\Screens\Http\Controllers\SharedScreenController;
use App\Domains\Screens\Http\Controllers\TvController;
use App\Domains\Teams\Http\Controllers\InvitationController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * Pages reached without signing in: first-run setup, invitations, the watching mails' stop links, TVs and shared or previewed screens.
 */

// First-run setup and recovery; 404 wherever it is not open. See docs/administration.md.
Route::get('setup', [SetupController::class, 'show'])->middleware(['throttle:30,1', EnsureSetupIsOpen::class, 'guest'])->name('setup');
Route::post('setup', [SetupController::class, 'store'])->middleware(['throttle:10,1', EnsureSetupIsOpen::class, 'guest'])->name('setup.store');

Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

// The signed "Stop watching" link of watching mails, without signing in. POST is also the
// mail client's one-click unsubscribe (List-Unsubscribe-Post), so it skips CSRF.
Route::get('watch/{issue}/stop', [WatchingController::class, 'confirm'])->middleware(['signed', 'throttle:30,1'])->name('watching.stop');
Route::post('watch/{issue}/stop', [WatchingController::class, 'stop'])->middleware(['signed', 'throttle:30,1'])->withoutMiddleware(ValidateCsrfToken::class)->name('watching.stop.confirm');
Route::post('invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');

// A TV's start page and what it asks without a session, by its httpOnly cookies; and a
// screen by its share link. See docs/screens.md.
Route::get('tv/{slot?}', [TvController::class, 'show'])->whereIn('slot', range(1, 9))->middleware('throttle:30,1')->name('tv');
Route::prefix('tv/{slot}')->whereIn('slot', range(1, 9))->middleware('throttle:screen-viewer')->group(function () {
    Route::post('pairing', [TvController::class, 'claim'])->name('tv.claim');
    Route::post('restore', [TvController::class, 'restore'])->withoutMiddleware('throttle:screen-viewer')->middleware('throttle:10,1')->name('tv.restore');
    Route::get('data', [TvController::class, 'data'])->name('tv.data');
    Route::get('heartbeat', [TvController::class, 'heartbeat'])->name('tv.heartbeat');
});
// A short-lived signed look at a screen, handed out to an agent over MCP.
Route::get('preview/screens/{screen:uuid}', [ScreenPreviewController::class, 'show'])->middleware('throttle:screen-viewer')->name('screens.preview');
Route::get('preview/screens/{screen:uuid}/data', [ScreenPreviewController::class, 'data'])->middleware('throttle:screen-viewer')->name('screens.preview.data');
Route::get('s/{key}', [SharedScreenController::class, 'show'])->where('key', 'ls_[a-z0-9]{40}')->middleware('throttle:screen-viewer')->name('screens.shared');
Route::get('s/{key}/data', [SharedScreenController::class, 'data'])->where('key', 'ls_[a-z0-9]{40}')->middleware('throttle:screen-viewer')->name('screens.shared.data');
