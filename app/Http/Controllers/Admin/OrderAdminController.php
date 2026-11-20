<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Billing\BillingClient;
use App\Services\Orders\OrderFulfiller;
use App\Services\Orders\OrganisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Orders & invoices generated on this site. Customer/license administration lives in billing.twsd.me. */
class OrderAdminController extends Controller
{
    public function orders(Request $request): View
    {
        $orders = Order::with('organisation', 'plan', 'invoice')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.orders', ['orders' => $orders]);
    }

    public function invoices(): View
    {
        return view('admin.invoices', ['invoices' => Invoice::with('organisation')->latest('issued_at')->paginate(25)]);
    }

    /** Confirm a bank transfer: marks paid, settles the invoice, activates the license, reports to billing. */
    public function markPaid(Request $request, Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_if($order->isPaid(), 409);
        $fulfiller->markPaid($order, $request->input('reference') ?: 'manual-'.now()->format('YmdHis'));

        return back()->with('toast', "{$order->number} marked as paid");
    }

    /** Record a refund made in the gateway dashboard. */
    public function refund(Request $request, Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_unless($order->isPaid(), 409);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:500']]);
        $fulfiller->refund($order, $data);
        $order->refresh();

        return back()->with(empty($order->meta['billing_action'])
            ? ['toast' => "{$order->number} refunded".($order->billing_license_id ? ' and its license revoked in billing' : '')]
            : ['toast' => "{$order->number} refunded here, but billing didn't record it yet. Retry from the order menu.", 'toast_tone' => 'danger']);
    }

    /** Retry the outstanding billing step: report the order, confirm a bank transfer, or record a refund. All idempotent in billing. */
    public function resync(Order $order, OrganisationService $orgs, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_unless(in_array($order->status, ['paid', 'awaiting_payment', 'refunded'], true), 409, 'Only paid, awaiting-transfer or refunded orders are sent to billing.');
        $orgs->syncToBilling($order->organisation);
        $fulfiller->retry($order);
        $order->refresh();

        $ok = $order->billing_license_id && empty($order->meta['billing_action']);

        return back()->with($ok
            ? ['toast' => "{$order->number} is up to date in billing (".($order->meta['license_number'] ?? $order->billing_license_id).')']
            : ['toast' => 'Billing still refused: '.($order->meta['billing_error'] ?? 'see the log'), 'toast_tone' => 'danger']);
    }

    /** Admin confirms they finished the step in billing.twsd.me (activate after bank transfer, revoke after refund). */
    public function resolveFlag(Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        $fulfiller->clearFlag($order);

        return back()->with('toast', "{$order->number}: marked as done in billing");
    }
}
