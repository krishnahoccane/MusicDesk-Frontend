<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;

/**
 * Client for billing.twsd.me's storefront API (TWSD Billing docs/LICENSING.md).
 *
 * billing.twsd.me is the system of record for customers (organisations), licenses,
 * activations and the license server. musicdesk.tech takes the payment, issues the
 * invoice, then reports the paid order; billing creates or changes the license.
 */
interface BillingClient
{
    /**
     * POST /customers at sign-up. Returns billing's cus_… id, or null when creation must wait
     * for the first order (e.g. no country yet; POST /orders then creates the customer).
     */
    public function createOrganisation(Organisation $org): ?string;

    /** PATCH /customers/{cus}. No-op until the organisation exists in billing. */
    public function updateOrganisation(Organisation $org): void;

    /**
     * POST /orders: report a paid (or bank-transfer pending) order. Creates the customer on
     * the first order, and the license for kind=new_license.
     *
     * @return array{customer_id: string, order_id: ?string, license_id: ?string, license_number: ?string, key: ?string, status: ?string}
     */
    public function submitOrder(Order $order, ?Invoice $invoice, string $paymentStatus = 'paid'): array;

    /**
     * A bank transfer reported earlier as pending has now been paid.
     * Returns false when billing has no way to record it (the license must be activated in billing's admin).
     */
    public function confirmPayment(Order $order, Invoice $invoice): bool;

    /** A refund was made on the storefront. Returns false when billing can't record it automatically. */
    public function recordRefund(Order $order, Invoice $invoice): bool;

    /** Licenses with activations, shaped like App\Support\Demo::licenses() for the portal views. */
    public function licenses(Organisation $org): array;

    /** POST /licenses/{lic}/reveal-key: full key, audited by billing. Never cache it. */
    public function revealKey(Organisation $org, string $licenseId, ?User $actor = null): string;

    /** @return array activation */
    public function addActivation(Organisation $org, string $licenseId, string $domain, ?string $environment, ?User $actor = null): array;

    /** @return array new activation */
    public function moveActivation(Organisation $org, string $licenseId, string $activationId, string $newDomain, ?User $actor = null): array;

    public function deactivateActivation(Organisation $org, string $licenseId, string $activationId, ?User $actor = null): void;

    public function requestCheckIn(Organisation $org, string $licenseId, string $activationId, ?User $actor = null): void;

    public function setAutoRenew(Organisation $org, string $licenseId, bool $autoRenew, ?User $actor = null): void;

    /** Drop cached license data for an organisation (after webhooks or changes). */
    public function forget(Organisation $org): void;
}
