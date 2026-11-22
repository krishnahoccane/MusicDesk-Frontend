<?php

namespace App\Services\Orders;

use App\Licensing\ActivationService;
use App\Licensing\LicenseService;
use App\Models\License;
use App\Models\LicenseAddon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens once money arrives (any gateway, or an admin marking a bank transfer paid):
 *   1. mark the order paid                     (idempotent: webhook + redirect can race)
 *   2. issue / settle the invoice
 *   3. apply the order to licensing: create the license (key generated here), or renew,
 *      add domains, upgrade, switch to lifetime, or issue a licensed add-on (its own key).
 */
class OrderFulfiller
{
    public function __construct(
        private InvoiceService $invoices,
        private LicenseService $licenses,
        private ActivationService $activations,
    ) {}

    public function markPaid(Order $order, ?string $paymentId = null, ?string $actor = null): Order
    {
        $order = DB::transaction(function () use ($order, $paymentId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->isPaid()) {
                return $order;
            }
            $order->update(['status' => 'paid', 'paid_at' => now(), 'gateway_payment_id' => $paymentId ?? $order->gateway_payment_id]);

            return $order;
        });

        $invoice = $this->invoices->forOrder($order);
        if ($invoice->status !== 'paid') {
            $this->invoices->markPaid($invoice);
        }

        $actor ??= 'payment: '.($order->gateway ?? 'manual');
        if ($order->license_id && $order->isNewLicense()) {
            // License created earlier as pending (bank transfer): activate it.
            $this->licenses->confirmPayment($order->license, (string) $order->gateway_payment_id, $actor);
        } else {
            $this->apply($order->fresh(), pending: false, actor: $actor);
        }

        return $order->fresh();
    }

    /**
     * Bank transfer: invoice issued unpaid now. A new license is created as pending (usable for
     * 14 days); changes to an existing license (renewal, upgrade…) wait until the money arrives.
     */
    public function awaitOffline(Order $order): Order
    {
        $order->update(['status' => 'awaiting_payment']);
        $this->invoices->forOrder($order);
        if ($order->isNewLicense()) {
            $this->apply($order->fresh(), pending: true, actor: 'checkout: bank transfer');
        }

        return $order->fresh();
    }

    /** Create or change the license for an order. Safe to call twice. */
    private function apply(Order $order, bool $pending, string $actor): void
    {
        $order->loadMissing('organisation', 'plan', 'license');

        if ($order->isNewLicense()) {
            if ($order->license_id) {
                return;
            }
            [$license] = $this->licenses->create($order->organisation, $order->plan, [
                'billing_type' => $order->billing_type,
                'domains_extra' => $order->extra_domains,
                'price_paid' => $order->total,
                'currency' => $order->currency,
                'pending' => $pending,
                'auto_renew' => $order->billing_type === 'annual',
            ], $actor);
            $order->update(['license_id' => $license->id]);

            if ($order->primary_domain) {
                try {
                    $this->activations->add($license, $order->primary_domain, null, $actor);
                } catch (\Throwable $e) {
                    // e.g. the domain is on another license: the customer adds it later from the portal.
                    Log::notice('Primary domain not pre-approved', ['order' => $order->number, 'error' => $e->getMessage()]);
                }
            }

            return;
        }

        $license = $order->license;
        if (! $license || ($order->meta['applied'] ?? false)) {
            return;
        }

        if ($order->kind === 'addon') {
            $addon = LicenseAddon::find($order->meta['addon_id'] ?? null);
            if ($addon) {
                [$addonLicense] = $this->licenses->createAddon($license, $addon, false, [
                    'billing_type' => $order->billing_type, 'price_paid' => $order->total, 'currency' => $order->currency,
                ], $actor);
                $order->update(['meta' => array_merge($order->meta ?? [], ['applied' => true, 'addon_license_id' => $addonLicense->id])]);
            }

            return;
        }

        match ($order->kind) {
            'renewal' => $this->licenses->renew($license, $actor),
            'updates_renewal' => $this->licenses->renewUpdates($license, $actor),
            'extra_domains' => $this->licenses->addDomains($license, max(1, $order->extra_domains), $actor),
            'change_plan' => $this->licenses->changePlan($license, $order->plan, $actor),
            'convert_lifetime' => $this->licenses->convertToLifetime($license, $actor),
            default => null,
        };
        $order->update(['meta' => array_merge($order->meta ?? [], ['applied' => true])]);
    }

    /** @param  array{reference?: ?string, reason?: ?string}  $details */
    public function refund(Order $order, array $details = [], ?string $actor = null): void
    {
        $reason = trim((string) ($details['reason'] ?? '')) ?: 'Refunded';
        $order->update([
            'status' => 'refunded', 'refunded_at' => now(),
            'refund_reference' => $details['reference'] ?? null, 'refund_reason' => $reason,
        ]);
        $order->invoice?->update(['status' => 'refunded']);

        if ($license = $order->license) {
            $actor ??= 'system';
            $addonLicense = $order->kind === 'addon' ? License::find($order->meta['addon_license_id'] ?? null) : null;
            if ($order->isNewLicense()) {
                $this->licenses->revokeForRefund($license, $reason, $actor);
            } elseif ($addonLicense) {
                $this->licenses->revokeForRefund($addonLicense, $reason, $actor);
            } else {
                // Renewals/upgrades: recorded; an admin decides what to undo on the license page.
                $this->licenses->event($license, 'order_refunded', ['order' => $order->number, 'kind' => $order->kind, 'reason' => $reason], $actor);
            }
        }
    }
}
