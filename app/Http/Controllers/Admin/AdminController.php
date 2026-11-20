<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\PaymentGateway;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $paidThisMonth = Invoice::query()->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->get();

        return view('admin.index', [
            'revenueUsd' => $paidThisMonth->sum(fn ($i) => $i->total / max($i->exchange_rate, 0.00000001)),
            'ordersCount' => Order::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            'awaiting' => Order::query()->where('status', 'awaiting_payment')->count(),
            'orgs' => Organisation::count(),
            'licensesActive' => License::query()->whereIn('status', [License::ACTIVE, License::PENDING])->count(),
            'expiring' => License::query()->where('status', License::ACTIVE)->whereBetween('expires_at', [now(), now()->addDays(30)])->count(),
            'pendingLicenses' => License::query()->where('status', License::PENDING)->count(),
            'latestRelease' => Release::latest(),
            'onlineInstalls' => LicenseActivation::query()->where('status', LicenseActivation::ACTIVE)->where('last_check_in_at', '>', now()->subHours(LicenseActivation::ONLINE_HOURS))->count(),
            'gatewaysOn' => PaymentGateway::query()->where('enabled', true)->pluck('name'),
            'ratesUpdated' => Currency::query()->where('code', '!=', 'USD')->max('rate_updated_at'),
            'recentOrders' => Order::with('organisation', 'plan')->latest()->take(8)->get(),
        ]);
    }
}
