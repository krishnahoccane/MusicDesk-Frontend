<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\LicenseActionController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\WebhookController;
use App\Models\Addon;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales site
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('marketing.home', ['plans' => Plan::active()->get()]))->name('home');
Route::get('/pricing', fn () => view('marketing.pricing', ['plans' => Plan::active()->get(), 'addons' => Addon::active()->get()]))->name('pricing');
Route::redirect('/labeldesk', '/pricing');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::get('/legal/{slug}', [PageController::class, 'legal'])->name('legal');

Route::post('/locale/currency', [LocaleController::class, 'currency'])->name('locale.currency');
Route::post('/locale/timezone', [LocaleController::class, 'timezone'])->name('locale.timezone');

/*
|--------------------------------------------------------------------------
| Checkout (payments + invoices are handled on this site)
|--------------------------------------------------------------------------
*/
Route::get('/checkout/{plan}', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout/{plan}/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
Route::post('/checkout/{plan}', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');

Route::middleware('auth')->prefix('orders/{order:number}')->name('checkout.')->group(function () {
    Route::get('/', [CheckoutController::class, 'complete'])->name('complete');
    Route::post('/pay', [CheckoutController::class, 'pay'])->name('pay');
    Route::post('/verify', [CheckoutController::class, 'verify'])->name('verify');
    Route::get('/return', [CheckoutController::class, 'return'])->name('return');
    Route::get('/cancel', [CheckoutController::class, 'cancel'])->name('cancel');
});

/*
|--------------------------------------------------------------------------
| Webhooks (signature-verified, CSRF-exempt)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/payments/{gateway}', [WebhookController::class, 'gateway'])->name('webhooks.gateway');
Route::post('/webhooks/wcc', [WebhookController::class, 'wcc'])->name('webhooks.wcc');
Route::get('/invoices/{invoice}/signed.pdf', [PortalController::class, 'invoiceSigned'])->middleware('signed')->name('invoices.signed');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.attempt');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Customer portal
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('dashboard')->name('dashboard')->group(function () {
    Route::get('/', [PortalController::class, 'index'])->name('');
    Route::get('/licenses', [PortalController::class, 'licensesPage'])->name('.licenses');
    Route::middleware('throttle:30,1')->prefix('/licenses/{license}')->name('.licenses.')->group(function () {
        Route::post('/reveal', [LicenseActionController::class, 'reveal'])->name('reveal');
        Route::post('/auto-renew', [LicenseActionController::class, 'autoRenew'])->name('auto-renew');
        Route::post('/domains', [LicenseActionController::class, 'addDomain'])->name('domains.add');
        Route::post('/domains/{activation}/move', [LicenseActionController::class, 'moveDomain'])->name('domains.move');
        Route::post('/domains/{activation}/check-in', [LicenseActionController::class, 'checkIn'])->name('domains.check-in');
        Route::delete('/domains/{activation}', [LicenseActionController::class, 'deactivateDomain'])->name('domains.deactivate');
    });
    Route::get('/downloads', [PortalController::class, 'downloads'])->name('.downloads');
    Route::get('/billing', [PortalController::class, 'billingPage'])->name('.billing');
    Route::post('/billing/details', [PortalController::class, 'updateBillingDetails'])->name('.billing.details');
    Route::get('/support', [PortalController::class, 'support'])->name('.support');
    Route::get('/settings', [PortalController::class, 'settings'])->name('.settings');
    Route::post('/settings/profile', [PortalController::class, 'updateProfile'])->name('.settings.profile');
    Route::get('/invoices/{invoice:number}', [PortalController::class, 'invoiceView'])->name('.invoices.show');
    Route::get('/invoices/{invoice:number}/pdf', [PortalController::class, 'invoicePdf'])->name('.invoices.pdf');
});

/*
|--------------------------------------------------------------------------
| Site admin (frontend management). Organisations, subscriptions and
| license suspension are administered in billing.twsd.me.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\AdminController::class, 'dashboard'])->name('dashboard');

    Route::get('/settings/{group}', [Admin\SettingsController::class, 'edit'])->name('settings');
    Route::post('/settings/{group}', [Admin\SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/{group}/reset', [Admin\SettingsController::class, 'reset'])->name('settings.reset');

    Route::get('/plans', [Admin\PlanController::class, 'index'])->name('plans');
    Route::post('/plans', [Admin\PlanController::class, 'store'])->name('plans.store');
    Route::put('/plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
    Route::delete('/plans/{plan}', [Admin\PlanController::class, 'destroy'])->name('plans.destroy');
    Route::post('/plans/addons', [Admin\PlanController::class, 'updateAddons'])->name('plans.addons');
    Route::post('/plans/coupons', [Admin\PlanController::class, 'updateCoupons'])->name('plans.coupons');

    Route::get('/currencies', [Admin\CurrencyController::class, 'index'])->name('currencies');
    Route::post('/currencies', [Admin\CurrencyController::class, 'update'])->name('currencies.update');
    Route::post('/currencies/add', [Admin\CurrencyController::class, 'store'])->name('currencies.store');
    Route::post('/currencies/refresh', [Admin\CurrencyController::class, 'refresh'])->name('currencies.refresh');

    Route::get('/gateways', [Admin\GatewayController::class, 'index'])->name('gateways');
    Route::post('/gateways/{code}', [Admin\GatewayController::class, 'update'])->name('gateways.update');

    Route::get('/pages', [Admin\PageAdminController::class, 'index'])->name('pages');
    Route::get('/pages/{page}', [Admin\PageAdminController::class, 'edit'])->name('pages.edit');
    Route::put('/pages/{page}', [Admin\PageAdminController::class, 'update'])->name('pages.update');

    Route::get('/orders', [Admin\OrderAdminController::class, 'orders'])->name('orders');
    Route::post('/orders/{order}/paid', [Admin\OrderAdminController::class, 'markPaid'])->name('orders.paid');
    Route::post('/orders/{order}/refund', [Admin\OrderAdminController::class, 'refund'])->name('orders.refund');
    Route::post('/orders/{order}/resync', [Admin\OrderAdminController::class, 'resync'])->name('orders.resync');
    Route::post('/orders/{order}/resolve', [Admin\OrderAdminController::class, 'resolveFlag'])->name('orders.resolve');
    Route::get('/invoices', [Admin\OrderAdminController::class, 'invoices'])->name('invoices');
});
