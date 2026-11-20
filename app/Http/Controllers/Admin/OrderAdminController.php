<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\LicenseService;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Orders\OrderFulfiller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Orders & invoices. Paying an order applies it to the license (create, renew, upgrade…). */
class OrderAdminController extends Controller
{
    public function orders(Request $request): View
    {
        $orders = Order::with('organisation', 'plan', 'invoice', 'license')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.orders', ['orders' => $orders]);
    }

    public function invoices(): View
    {
        return view('admin.invoices', ['invoices' => Invoice::with('organisation')->latest('issued_at')->paginate(25)]);
    }

    /** Confirm a bank transfer: marks paid, settles the invoice, activates / applies the license change. */
    public function markPaid(Request $request, Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_if($order->isPaid(), 409);
        $fulfiller->markPaid($order, $request->input('reference') ?: 'manual-'.now()->format('YmdHis'), LicenseService::actor($request->user()));

        return back()->with('toast', "{$order->number} marked as paid".($order->fresh()->license ? ' and applied to '.$order->fresh()->license->number : ''));
    }

    /** Record a refund made in the gateway dashboard. A refunded new license is revoked. */
    public function refund(Request $request, Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_unless($order->isPaid(), 409);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:500']]);
        $fulfiller->refund($order, $data, LicenseService::actor($request->user()));
        $order->refresh();

        $note = $order->license && $order->isNewLicense() ? ' and '.$order->license->number.' revoked' : ($order->license ? '. Review '.$order->license->number.' if the change should be undone' : '');

        return back()->with('toast', "{$order->number} refunded$note");
    }
}
