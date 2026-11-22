<?php

use App\Http\Controllers\Api\LicenseServerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| License server for LabelDesk installs (docs/licensing.md)
|--------------------------------------------------------------------------
| Base: /api/license/v1 · JSON · 30 requests/minute per IP.
*/
Route::prefix('license/v1')->middleware('throttle:license-server')->name('license.')->group(function () {
    Route::post('/activate', [LicenseServerController::class, 'activate'])->name('activate');
    Route::post('/check-in', [LicenseServerController::class, 'checkIn'])->name('check-in');
    Route::post('/deactivate', [LicenseServerController::class, 'deactivate'])->name('deactivate');
    Route::get('/public-key', [LicenseServerController::class, 'publicKey'])->name('public-key');

    // LabelDesk add-ons (plugins), each activated with its own add-on key.
    Route::post('/addons/activate', [LicenseServerController::class, 'activateAddon'])->name('addons.activate');
    Route::post('/addons/check-in', [LicenseServerController::class, 'checkInAddon'])->name('addons.check-in');
    Route::post('/addons/deactivate', [LicenseServerController::class, 'deactivateAddon'])->name('addons.deactivate');
});

// Security webhook: LabelDesk installs report failed license checks here. 10/minute per IP.
Route::post('license/v1/report', [LicenseServerController::class, 'report'])->middleware('throttle:license-report')->name('license.report');
