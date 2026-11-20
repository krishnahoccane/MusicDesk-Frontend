<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Invoice;
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
            // Orders billing hasn't recorded, or that need a manual step in billing (activate / revoke).
            'unsynced' => Order::query()->whereIn('status', ['paid', 'awaiting_payment'])->whereNull('billing_license_id')->count()
                + Order::query()->whereNotNull('meta->billing_action')->count(),
            'gatewaysOn' => PaymentGateway::query()->where('enabled', true)->pluck('name'),
            'ratesUpdated' => Currency::query()->where('code', '!=', 'USD')->max('rate_updated_at'),
            'recentOrders' => Order::with('organisation', 'plan')->latest()->take(8)->get(),
        ]);
    }
}
