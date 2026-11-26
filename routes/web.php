<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LicenseActionController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\WebhookController;
use App\Models\Addon;
use App\Models\LicenseAddon;
use App\Models\Plan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales site
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('marketing.home', ['plans' => Plan::active()->get()]))->name('home');
Route::get('/pricing', fn () => view('marketing.pricing', ['plans' => Plan::active()->get(), 'addons' => Addon::active()->get(), 'licenseAddons' => LicenseAddon::active()->with('plans')->get()]))->name('pricing');
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
Route::get('/trial/{plan}', [\App\Http\Controllers\TrialController::class, 'show'])->name('trial');
Route::post('/trial/{plan}', [\App\Http\Controllers\TrialController::class, 'store'])->middleware('throttle:5,1')->name('trial.store');
Route::post('/checkout/{plan}/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
Route::post('/checkout/{plan}', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');

Route::middleware('auth')->prefix('dashboard/licenses/{license}/buy/{kind}')->name('licenses.')->whereIn('kind', ['renewal', 'updates_renewal', 'extra_domains', 'change_plan', 'convert_lifetime', 'addon'])->group(function () {
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
// LabelDesk links administrators here (LicenseAuthority::ADDONS_URL) to download add-ons.
Route::get('/account/addons', fn () => redirect()->to(route('dashboard.downloads').'#addons'))->middleware('auth')->name('account.addons');

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
    Route::get('/support', [SupportController::class, 'index'])->name('.support');
    Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('.support.store');
    Route::post('/support/{ticket:number}/reply', [SupportController::class, 'reply'])->middleware('throttle:20,1')->name('.support.reply');
    Route::post('/support/{ticket:number}/status', [SupportController::class, 'status'])->name('.support.status');
    Route::get('/support/{ticket:number}/files/{message}/{file}', [SupportController::class, 'attachment'])->name('.support.attachment');
    Route::get('/settings', [PortalController::class, 'settings'])->name('.settings');
    Route::post('/settings/profile', [PortalController::class, 'updateProfile'])->name('.settings.profile');
    Route::get('/invoices/{invoice:number}', [PortalController::class, 'invoiceView'])->name('.invoices.show');
    Route::get('/invoices/{invoice:number}/pdf', [PortalController::class, 'invoicePdf'])->name('.invoices.pdf');
});

/*
|--------------------------------------------------------------------------
| Site admin: licenses, security status, customers, releases, support,
| orders, invoices, payments
| and storefront settings.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\AdminController::class, 'dashboard'])->name('dashboard');

    // Each area needs its permission (AdminRole::PERMISSIONS). Settings groups map to
    // storefront (branding, home, contact, pricing) or payments (invoice, general).
    Route::middleware('admin.can:settings')->group(function () {
        Route::get('/settings/{group}', [Admin\SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings/{group}', [Admin\SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/{group}/reset', [Admin\SettingsController::class, 'reset'])->name('settings.reset');
    });

    Route::middleware('admin.can:storefront')->group(function () {
        Route::get('/plans', [Admin\PlanController::class, 'index'])->name('plans');
        Route::post('/plans', [Admin\PlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
        Route::delete('/plans/{plan}', [Admin\PlanController::class, 'destroy'])->name('plans.destroy');
        Route::post('/plans/addons', [Admin\PlanController::class, 'updateAddons'])->name('plans.addons');
        Route::post('/plans/coupons', [Admin\PlanController::class, 'updateCoupons'])->name('plans.coupons');
        Route::post('/plans/license-addons', [Admin\PlanController::class, 'storeLicenseAddon'])->name('plans.license-addons.store');
        Route::put('/plans/license-addons/{addon}', [Admin\PlanController::class, 'updateLicenseAddon'])->name('plans.license-addons.update');
        Route::delete('/plans/license-addons/{addon}', [Admin\PlanController::class, 'destroyLicenseAddon'])->name('plans.license-addons.destroy');

        Route::get('/pages', [Admin\PageAdminController::class, 'index'])->name('pages');
        Route::get('/pages/{page}', [Admin\PageAdminController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}', [Admin\PageAdminController::class, 'update'])->name('pages.update');
    });

    Route::middleware('admin.can:payments')->group(function () {
        Route::get('/currencies', [Admin\CurrencyController::class, 'index'])->name('currencies');
        Route::post('/currencies', [Admin\CurrencyController::class, 'update'])->name('currencies.update');
        Route::post('/currencies/add', [Admin\CurrencyController::class, 'store'])->name('currencies.store');
        Route::post('/currencies/refresh', [Admin\CurrencyController::class, 'refresh'])->name('currencies.refresh');

        Route::get('/gateways', [Admin\GatewayController::class, 'index'])->name('gateways');
        Route::post('/gateways/{code}', [Admin\GatewayController::class, 'update'])->name('gateways.update');
    });

    Route::middleware('admin.can:sales')->group(function () {
        Route::get('/orders', [Admin\OrderAdminController::class, 'orders'])->name('orders');
        Route::post('/orders/{order}/paid', [Admin\OrderAdminController::class, 'markPaid'])->name('orders.paid');
        Route::post('/orders/{order}/refund', [Admin\OrderAdminController::class, 'refund'])->name('orders.refund');
        Route::delete('/orders/{order}', [Admin\DeleteController::class, 'order'])->middleware('admin.can:delete')->name('orders.destroy');
        Route::get('/invoices', [Admin\OrderAdminController::class, 'invoices'])->name('invoices');
    });

    // Registered before /licenses/{license} so "status" isn't read as a license.
    Route::middleware('admin.can:security')->group(function () {
        Route::get('/licenses/status', [Admin\LicenseStatusController::class, 'index'])->name('license-status');
        Route::post('/licenses/status/block', [Admin\LicenseStatusController::class, 'blockDomain'])->name('licenses.alerts.block-domain');
        Route::post('/licenses/status/{alert}/block', [Admin\LicenseStatusController::class, 'block'])->name('licenses.alerts.block');
        Route::put('/licenses/status/{alert}', [Admin\LicenseStatusController::class, 'update'])->name('licenses.alerts.update');
    });

    Route::middleware('admin.can:licenses')->group(function () {
        Route::get('/licenses', [Admin\LicenseAdminController::class, 'index'])->name('licenses');
        Route::get('/licenses/create', [Admin\LicenseAdminController::class, 'create'])->name('licenses.create');
        Route::post('/licenses', [Admin\LicenseAdminController::class, 'store'])->name('licenses.store');
        Route::get('/licenses/{license}', [Admin\LicenseAdminController::class, 'show'])->name('licenses.show');
        Route::put('/licenses/{license}', [Admin\LicenseAdminController::class, 'update'])->name('licenses.update');
        Route::post('/licenses/{license}/status', [Admin\LicenseAdminController::class, 'status'])->name('licenses.status');
        Route::post('/licenses/{license}/reveal', [Admin\LicenseAdminController::class, 'reveal'])->middleware('throttle:30,1')->name('licenses.reveal');
        Route::post('/licenses/{license}/regenerate', [Admin\LicenseAdminController::class, 'regenerate'])->name('licenses.regenerate');
        Route::delete('/licenses/{license}', [Admin\DeleteController::class, 'license'])->middleware('admin.can:delete')->name('licenses.destroy');
        Route::post('/licenses/{license}/addons', [Admin\LicenseAdminController::class, 'issueAddon'])->name('licenses.addons.store');
        Route::post('/licenses/{license}/domains', [Admin\LicenseAdminController::class, 'addDomain'])->name('licenses.domains.add');
        Route::delete('/licenses/{license}/domains/{activation}', [Admin\LicenseAdminController::class, 'deactivateDomain'])->name('licenses.domains.deactivate');
        Route::post('/licenses/{license}/domains/{activation}/check-in', [Admin\LicenseAdminController::class, 'checkIn'])->name('licenses.domains.check-in');
    });

    Route::middleware('admin.can:customers')->group(function () {
        Route::get('/customers', [Admin\CustomerAdminController::class, 'index'])->name('customers');
        Route::get('/customers/{organisation}', [Admin\CustomerAdminController::class, 'show'])->name('customers.show');
        Route::put('/customers/{organisation}', [Admin\CustomerAdminController::class, 'update'])->name('customers.update');
        Route::delete('/customers/{organisation}', [Admin\DeleteController::class, 'customer'])->middleware('admin.can:delete')->name('customers.destroy');
    });

    Route::middleware('admin.can:support')->group(function () {
        Route::get('/support', [Admin\SupportAdminController::class, 'index'])->name('support');
        Route::get('/support/{ticket:number}', [Admin\SupportAdminController::class, 'show'])->name('support.show');
        Route::post('/support/{ticket:number}/reply', [Admin\SupportAdminController::class, 'reply'])->name('support.reply');
        Route::put('/support/{ticket:number}', [Admin\SupportAdminController::class, 'update'])->name('support.update');
        Route::get('/support/{ticket:number}/files/{message}/{file}', [Admin\SupportAdminController::class, 'attachment'])->name('support.attachment');
    });

    Route::middleware('admin.can:releases')->group(function () {
        Route::get('/releases', [Admin\ReleaseAdminController::class, 'index'])->name('releases');
        Route::post('/releases', [Admin\ReleaseAdminController::class, 'store'])->name('releases.store');
        Route::put('/releases/{release}', [Admin\ReleaseAdminController::class, 'update'])->name('releases.update');
        Route::delete('/releases/{release}', [Admin\ReleaseAdminController::class, 'destroy'])->name('releases.destroy');
    });

    // Administration team
    Route::middleware('admin.can:team')->group(function () {
        Route::get('/team', [Admin\TeamController::class, 'index'])->name('team');
        Route::post('/team/members', [Admin\TeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
        Route::put('/team/members/{member}', [Admin\TeamController::class, 'updateMember'])->name('team.members.update');
        Route::post('/team/members/{member}/resend', [Admin\TeamController::class, 'resendInvite'])->middleware('throttle:10,1')->name('team.members.resend');
        Route::delete('/team/members/{member}', [Admin\TeamController::class, 'removeMember'])->name('team.members.remove');
        Route::post('/team/roles', [Admin\TeamController::class, 'storeRole'])->name('team.roles.store');
        Route::put('/team/roles/{role}', [Admin\TeamController::class, 'updateRole'])->name('team.roles.update');
        Route::delete('/team/roles/{role}', [Admin\TeamController::class, 'destroyRole'])->name('team.roles.destroy');
    });
});

// Team invitations: the signed link from the invite email sets the new member's password.
Route::get('/team/join/{member}', [Admin\TeamController::class, 'showAccept'])->middleware('signed')->name('team.accept');
Route::post('/team/join/{member}', [Admin\TeamController::class, 'accept'])->middleware(['signed', 'throttle:10,1'])->name('team.accept.store');

/*
|--------------------------------------------------------------------------
| Web installer (only reachable until installation finishes; see RequireInstallation)
|--------------------------------------------------------------------------
*/
Route::prefix('install')->name('install.')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/{step}', [InstallController::class, 'show'])->whereIn('step', ['requirements', 'database', 'redis', 'mail', 'admin'])->name('step');
    Route::post('/requirements', [InstallController::class, 'requirements'])->name('requirements');
    Route::post('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/redis', [InstallController::class, 'redis'])->name('redis');
    Route::post('/mail', [InstallController::class, 'mail'])->name('mail');
    Route::post('/admin', [InstallController::class, 'admin'])->name('admin');
});
