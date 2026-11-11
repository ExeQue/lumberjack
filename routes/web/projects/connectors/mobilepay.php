<?php

declare(strict_types=1);

use App\Domains\Connectors\MobilePay\Http\Controllers\MobilePayConnectionController;
use App\Domains\Connectors\MobilePay\Http\Controllers\MobilePayController;
use Illuminate\Support\Facades\Route;

/*
 * The Vipps MobilePay connector: its page and connecting a ledger. See docs/connectors/mobilepay.md. Loaded inside the project group.
 */

Route::post('mobilepay/connection', [MobilePayConnectionController::class, 'store'])->name('connectors.mobilepay.connection.store');
Route::get('mobilepay/{dataSourceConnection?}', MobilePayController::class)->name('connectors.mobilepay.index');
