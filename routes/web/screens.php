<?php

declare(strict_types=1);

use App\Domains\Screens\Http\Controllers\DataSourcePreviewController;
use App\Domains\Screens\Http\Controllers\DeviceController;
use App\Domains\Screens\Http\Controllers\DisplayController;
use App\Domains\Screens\Http\Controllers\PairController;
use App\Domains\Screens\Http\Controllers\SavedScreenTemplateController;
use App\Domains\Screens\Http\Controllers\ScreenController;
use App\Domains\Screens\Http\Controllers\ScreenFileController;
use App\Domains\Screens\Http\Controllers\ScreenShareController;
use Illuminate\Support\Facades\Route;

/*
 * Status screens: the team's wall displays, its paired TVs and pairing one. See docs/screens.md. Loaded inside the auth group.
 */

// Status screens: the team's wall displays. See docs/screens.md.
Route::get('present/screens', [ScreenController::class, 'index'])->name('screens.index');
// The team's paired TVs: a tab of Screens.
Route::get('present/displays', [DisplayController::class, 'index'])->name('team.displays');
Route::put('present/displays/{display:uuid}', [DisplayController::class, 'update'])->name('displays.update');
Route::post('present/displays/{display:uuid}/reload', [DisplayController::class, 'reload'])->name('displays.reload');
Route::post('present/displays/{display:uuid}/identify', [DisplayController::class, 'identify'])->name('displays.identify');
Route::delete('present/displays/{display:uuid}', [DisplayController::class, 'destroy'])->name('displays.destroy');
Route::put('present/devices/{device:uuid}', [DeviceController::class, 'update'])->name('devices.update');
Route::post('present/devices/{device:uuid}/reload', [DeviceController::class, 'reload'])->name('devices.reload');
Route::delete('present/devices/{device:uuid}', [DeviceController::class, 'destroy'])->name('devices.destroy');
Route::post('present/screens', [ScreenController::class, 'store'])->name('screens.store');
Route::post('present/screens/import', [ScreenFileController::class, 'import'])->middleware('throttle:20,1')->name('screens.import');
Route::post('present/screens/import/preview', [ScreenFileController::class, 'preview'])->middleware('throttle:60,1')->name('screens.import.preview');
Route::get('present/screens/{screen:uuid}/export', [ScreenFileController::class, 'export'])->name('screens.export');
Route::post('present/screens/{screen:uuid}/copy', [ScreenFileController::class, 'copy'])->middleware('throttle:20,1')->name('screens.copy');
Route::post('present/screens/{screen:uuid}/template', [SavedScreenTemplateController::class, 'store'])->name('screens.template');
Route::put('present/screen-templates/{template:uuid}', [SavedScreenTemplateController::class, 'update'])->name('screen-templates.update');
Route::delete('present/screen-templates/{template:uuid}', [SavedScreenTemplateController::class, 'destroy'])->name('screen-templates.destroy');
Route::get('present/screens/{screen:uuid}', [ScreenController::class, 'show'])->name('screens.show');
Route::get('present/screens/{screen:uuid}/edit', [ScreenController::class, 'edit'])->name('screens.edit');
Route::get('present/screens/{screen:uuid}/data', [ScreenController::class, 'data'])->middleware('throttle:120,1')->name('screens.data');
Route::get('present/screens/{screen:uuid}/data-sources/{dataSource}/preview', DataSourcePreviewController::class)->middleware('throttle:60,1')->name('screens.data-sources.preview');
Route::get('present/screens/{screen:uuid}/viewers', [ScreenController::class, 'viewers'])->name('screens.viewers');
Route::put('present/screens/{screen:uuid}', [ScreenController::class, 'update'])->name('screens.update');
Route::put('present/screens/{screen:uuid}/layout', [ScreenController::class, 'layout'])->name('screens.layout');
Route::put('present/screens/{screen:uuid}/widgets/{widget}', [ScreenController::class, 'widget'])->where('widget', '[A-Za-z0-9_-]{1,40}')->name('screens.widget');
Route::get('present/screens/{screen:uuid}/versions/{version:uuid}', [ScreenController::class, 'version'])->name('screens.versions.preview');
Route::get('present/screens/{screen:uuid}/versions/{version:uuid}/data', [ScreenController::class, 'versionData'])->name('screens.versions.data');
Route::post('present/screens/{screen:uuid}/versions/{version:uuid}/restore', [ScreenController::class, 'restore'])->name('screens.versions.restore');
Route::post('present/screens/{screen:uuid}/reload', [ScreenController::class, 'reload'])->name('screens.reload');
Route::post('present/screens/{screen:uuid}/duplicate', [ScreenController::class, 'duplicate'])->name('screens.duplicate');
Route::delete('present/screens/{screen:uuid}', [ScreenController::class, 'destroy'])->name('screens.destroy');
Route::put('present/screens/{screen:uuid}/share', [ScreenShareController::class, 'update'])->name('screens.share');
Route::post('present/screens/{screen:uuid}/share/rotate', [ScreenShareController::class, 'rotate'])->name('screens.share.rotate');

// Pairing a TV by the code it shows, and the team's paired TVs.
Route::get('pair', [PairController::class, 'index'])->name('pair');
Route::get('pair/{code}', [PairController::class, 'show'])->where('code', '[A-Za-z0-9-]{1,12}')->middleware('throttle:tv-pair')->name('pair.show');
Route::post('pair/{code}', [PairController::class, 'confirm'])->where('code', '[A-Za-z0-9-]{1,12}')->middleware('throttle:tv-pair')->name('pair.confirm');
