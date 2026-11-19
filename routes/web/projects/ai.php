<?php

declare(strict_types=1);

use App\Domains\Ai\Http\Controllers\AiBudgetController;
use App\Domains\Ai\Http\Controllers\AiKeyController;
use App\Domains\Ai\Http\Controllers\AiPriceController;
use App\Domains\Ai\Http\Controllers\AiTargetController;
use App\Domains\Ai\Http\Controllers\AiUsageController;
use Illuminate\Support\Facades\Route;

/*
 * A project's AI usage: totals and cost, keys, key owners, actors, and prices. Loaded inside the project group.
 */

Route::get('ai', [AiUsageController::class, 'index'])->name('ai.index');
// The owner or actor by type and id in the query, as Activity takes them: either may hold a slash.
Route::get('ai/owner', [AiUsageController::class, 'owner'])->name('ai.owners.show');
Route::get('ai/actor', [AiUsageController::class, 'actor'])->name('ai.actors.show');
Route::post('ai/keys/lookup', [AiKeyController::class, 'lookup'])->middleware('throttle:30,1,ai-key-lookup')->name('ai.keys.lookup');
Route::get('ai/keys/{aiKey:uuid}', [AiKeyController::class, 'show'])->name('ai.keys.show');
Route::get('ai/targets', [AiTargetController::class, 'search'])->middleware('throttle:120,1,ai.targets.search')->name('ai.targets.search');
Route::put('ai/keys/{aiKey:uuid}/payer', [AiKeyController::class, 'payer'])->name('ai.keys.payer');
Route::put('ai/keys/{aiKey:uuid}/name', [AiKeyController::class, 'name'])->name('ai.keys.name');
Route::get('ai/prices', [AiPriceController::class, 'index'])->name('ai.prices.index');
Route::post('ai/prices', [AiPriceController::class, 'store'])->name('ai.prices.store');
Route::put('ai/payer', [AiPriceController::class, 'payer'])->name('ai.payer');
// A team's price is not the project's own, so the policy checks it applies to the project instead.
Route::put('ai/prices/{aiPrice:uuid}', [AiPriceController::class, 'update'])->withoutScopedBindings()->name('ai.prices.update');
Route::delete('ai/prices/{aiPrice:uuid}', [AiPriceController::class, 'destroy'])->withoutScopedBindings()->name('ai.prices.destroy');
Route::get('ai/budgets', [AiBudgetController::class, 'index'])->name('ai.budgets.index');
Route::post('ai/budgets', [AiBudgetController::class, 'store'])->name('ai.budgets.store');
Route::put('ai/budgets/{aiBudget:uuid}', [AiBudgetController::class, 'update'])->name('ai.budgets.update');
Route::delete('ai/budgets/{aiBudget:uuid}', [AiBudgetController::class, 'destroy'])->name('ai.budgets.destroy');
