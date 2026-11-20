<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Billing\BillingClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens once money arrives (any gateway, or an admin marking a bank transfer paid):
 *   1. mark the order paid                  (idempotent: webhook + redirect can race)
 *   2. issue / settle the invoice           (generated here on musicdesk.tech, never on billing)
 *   3. report the paid order to billing     (POST /orders: creates the customer on the first
 *                                            order and the license; billing generates the key)
 */
class OrderFulfiller
{
    public function __construct(private InvoiceService $invoices, private BillingClient $billing) {}

    public function markPaid(Order $order, ?string $paymentId = null): Order
    {
        $order = DB::transaction(function () use ($order, $paymentId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->isPaid()) {
                return $order;
            }
            $wasPending = $order->status === 'awaiting_payment';
            $order->update([
                'status' => 'paid', 'paid_at' => now(),
                'gateway_payment_id' => $paymentId ?? $order->gateway_payment_id,
                'meta' => array_merge($order->meta ?? [], ['was_pending' => $wasPending]),
            ]);

            return $order;
        });

        $invoice = $this->invoices->forOrder($order);
        if ($invoice->status !== 'paid') {
            $this->invoices->markPaid($invoice);
        }

        if ($order->billing_license_id) {
            // Reported earlier as a pending bank transfer: POST /orders/{ord}/confirm-payment activates it.
            $this->confirmInBilling($order, $invoice->fresh());
        } else {
            $this->report($order, 'paid');
        }

        return $order->fresh();
    }

    /** Bank transfer: invoice issued unpaid now; billing creates a pending license usable for 14 days. */
    public function awaitOffline(Order $order): Order
    {
        $order->update(['status' => 'awaiting_payment']);
        $this->invoices->forOrder($order);
        $this->report($order, 'pending');

        return $order->fresh();
    }

    /** POST /orders. Safe to call again: the idempotency key is fixed per order. */
    public function report(Order $order, string $paymentStatus): void
    {
        if ($order->billing_license_id) {
            return;
        }
        $order->loadMissing('organisation');
        try {
            $result = $this->billing->submitOrder($order, $order->invoice()->first(), $paymentStatus);

            if (! $order->organisation->billing_org_id && $result['customer_id']) {
                $order->organisation->update(['billing_org_id' => $result['customer_id'], 'billing_synced_at' => now()]);
            }

            $order->update([
                'billing_license_id' => $result['license_id'],
                'license_key' => $result['key'],     // only the local stand-in returns a key; live billing reveals on request
                'meta' => array_merge($order->meta ?? [], array_filter([
                    'license_number' => $result['license_number'],
                    'billing_order_id' => $result['order_id'],
                    'billing_error' => null,
                ])),
            ]);
        } catch (\Throwable $e) {
            Log::error('Billing POST /orders failed', ['order' => $order->number, 'error' => $e->getMessage()]);
            $this->flag($order, 'report', $e->getMessage());
        }
    }

    public function confirmInBilling(Order $order, \App\Models\Invoice $invoice): void
    {
        try {
            $ok = $this->billing->confirmPayment($order, $invoice);
        } catch (\Throwable $e) {
            Log::error('Billing confirm-payment failed', ['order' => $order->number, 'error' => $e->getMessage()]);
            $ok = false;
        }
        $ok ? $this->clearFlag($order->fresh())
            : $this->flag($order, 'confirm_payment', 'Billing didn’t confirm this bank transfer yet. Retry, or set the license to active in billing.twsd.me.');
    }

    /** @param  array{reference?: ?string, reason?: ?string}  $details */
    public function refund(Order $order, array $details = []): void
    {
        $order->update(['status' => 'refunded', 'meta' => array_merge($order->meta ?? [], array_filter([
            'refund_reference' => $details['reference'] ?? null,
            'refund_reason' => $details['reason'] ?? null,
        ]))]);
        if ($invoice = $order->invoice) {
            $invoice->update(['status' => 'refunded']);
            if ($order->billing_license_id) {
                $this->refundInBilling($order->fresh(), $invoice);
            }
        }
    }

    public function refundInBilling(Order $order, \App\Models\Invoice $invoice): void
    {
        try {
            $ok = $this->billing->recordRefund($order, $invoice);
        } catch (\Throwable $e) {
            Log::error('Billing refund failed', ['order' => $order->number, 'error' => $e->getMessage()]);
            $ok = false;
        }
        $ok ? $this->clearFlag($order->fresh())
            : $this->flag($order, 'revoke', 'Billing didn’t record this refund yet. Retry, or revoke the license in billing.twsd.me.');
    }

    /** Retry whatever billing step is outstanding for an order (admin "Retry in billing"). */
    public function retry(Order $order): void
    {
        $order->refresh();
        if (! $order->billing_license_id) {
            $this->report($order, $order->isPaid() ? 'paid' : 'pending');

            return;
        }
        match ($order->meta['billing_action'] ?? null) {
            'confirm_payment' => $order->invoice ? $this->confirmInBilling($order, $order->invoice) : null,
            'revoke' => $order->invoice ? $this->refundInBilling($order, $order->invoice) : null,
            default => null,
        };
    }

    /** Something billing couldn't do automatically; shown to admins in /admin/orders. */
    private function flag(Order $order, string $action, string $message): void
    {
        $order->update(['meta' => array_merge($order->fresh()->meta ?? [], ['billing_action' => $action, 'billing_error' => $message])]);
    }

    public function clearFlag(Order $order): void
    {
        $meta = $order->meta ?? [];
        unset($meta['billing_action'], $meta['billing_error']);
        $order->update(['meta' => $meta]);
    }
}
