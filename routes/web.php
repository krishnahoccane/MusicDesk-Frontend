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

Route::middleware('auth')->prefix('dashboard/licenses/{license}/buy/{kind}')->name('licenses.')->whereIn('kind', ['renewal', 'updates_renewal', 'extra_domains', 'change_plan', 'convert_lifetime'])->group(function () {
    Route::get('/', [CheckoutController::class, 'licenseShow'])->name('buy');
    Route::post('/quote', [CheckoutController::class, 'licenseQuote'])->name('buy.quote');
    Route::post('/', [CheckoutController::class, 'licenseStore'])->middleware('throttle:10,1')->name('buy.store');
});

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
        Route::get('/certificate', [LicenseActionController::class, 'certificate'])->name('certificate');
        Route::post('/offline-activation', [LicenseActionController::class, 'offlineActivation'])->name('offline');
    });
    Route::get('/downloads', [PortalController::class, 'downloads'])->name('.downloads');
    Route::get('/downloads/{release}', [PortalController::class, 'download'])->middleware('throttle:20,1')->name('.downloads.file');
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
| Site admin: licenses, customers, releases, orders, invoices, payments
| and storefront settings.
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
    Route::get('/invoices', [Admin\OrderAdminController::class, 'invoices'])->name('invoices');

    // Licensing
    Route::get('/licenses', [Admin\LicenseAdminController::class, 'index'])->name('licenses');
    Route::get('/licenses/create', [Admin\LicenseAdminController::class, 'create'])->name('licenses.create');
    Route::post('/licenses', [Admin\LicenseAdminController::class, 'store'])->name('licenses.store');
    Route::get('/licenses/{license}', [Admin\LicenseAdminController::class, 'show'])->name('licenses.show');
    Route::put('/licenses/{license}', [Admin\LicenseAdminController::class, 'update'])->name('licenses.update');
    Route::post('/licenses/{license}/status', [Admin\LicenseAdminController::class, 'status'])->name('licenses.status');
    Route::post('/licenses/{license}/reveal', [Admin\LicenseAdminController::class, 'reveal'])->middleware('throttle:30,1')->name('licenses.reveal');
    Route::post('/licenses/{license}/regenerate', [Admin\LicenseAdminController::class, 'regenerate'])->name('licenses.regenerate');
    Route::post('/licenses/{license}/domains', [Admin\LicenseAdminController::class, 'addDomain'])->name('licenses.domains.add');
    Route::delete('/licenses/{license}/domains/{activation}', [Admin\LicenseAdminController::class, 'deactivateDomain'])->name('licenses.domains.deactivate');
    Route::post('/licenses/{license}/domains/{activation}/check-in', [Admin\LicenseAdminController::class, 'checkIn'])->name('licenses.domains.check-in');

    Route::get('/customers', [Admin\CustomerAdminController::class, 'index'])->name('customers');
    Route::get('/customers/{organisation}', [Admin\CustomerAdminController::class, 'show'])->name('customers.show');
    Route::put('/customers/{organisation}', [Admin\CustomerAdminController::class, 'update'])->name('customers.update');

    Route::get('/releases', [Admin\ReleaseAdminController::class, 'index'])->name('releases');
    Route::post('/releases', [Admin\ReleaseAdminController::class, 'store'])->name('releases.store');
    Route::put('/releases/{release}', [Admin\ReleaseAdminController::class, 'update'])->name('releases.update');
    Route::delete('/releases/{release}', [Admin\ReleaseAdminController::class, 'destroy'])->name('releases.destroy');
});

/*
|--------------------------------------------------------------------------
| Web installer (only reachable until installation finishes; see RequireInstallation)
|--------------------------------------------------------------------------
*/
Route::prefix('install')->name('install.')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [\App\Http\Controllers\InstallController::class, 'index'])->name('index');
    Route::get('/{step}', [\App\Http\Controllers\InstallController::class, 'show'])->whereIn('step', ['requirements', 'database', 'redis', 'mail', 'admin'])->name('step');
    Route::post('/requirements', [\App\Http\Controllers\InstallController::class, 'requirements'])->name('requirements');
    Route::post('/database', [\App\Http\Controllers\InstallController::class, 'database'])->name('database');
    Route::post('/redis', [\App\Http\Controllers\InstallController::class, 'redis'])->name('redis');
    Route::post('/mail', [\App\Http\Controllers\InstallController::class, 'mail'])->name('mail');
    Route::post('/admin', [\App\Http\Controllers\InstallController::class, 'admin'])->name('admin');
});
