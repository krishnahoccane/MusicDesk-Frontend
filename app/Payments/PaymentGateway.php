<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Contract every payment gateway driver implements.
 * To add a gateway (Cashfree, PhonePe, Paystack, Mollie, Square…):
 *   1. create a class in app/Payments/Gateways extending AbstractGateway
 *   2. register it in GatewayManager::DRIVERS
 *   3. add a row to payment_gateways (the seeder does this) and configure it in /admin/gateways
 */
interface PaymentGateway
{
    public function code(): string;

    public function name(): string;

    /** Admin form fields: key => [label, secret(bool), help]. */
    public function credentialFields(): array;

    /** Currencies the gateway can charge in; null = any. */
    public function supportedCurrencies(): ?array;

    /** Are the required credentials filled in? */
    public function isConfigured(): bool;

    /** Start a payment for an order. */
    public function createPayment(Order $order, array $urls): PaymentAction;

    /** Verify the customer's return/callback (redirect or JS handler). */
    public function verifyReturn(Order $order, Request $request): PaymentResult;

    /** Handle a server-to-server webhook. Return null to ignore the event. */
    public function handleWebhook(Request $request): ?WebhookResult;
}
