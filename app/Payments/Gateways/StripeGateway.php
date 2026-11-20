<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\PaymentAction;
use App\Payments\PaymentResult;
use App\Payments\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe: hosted Checkout Sessions (no card data touches our servers).
 * Docs: https://docs.stripe.com/api/checkout/sessions/create
 */
class StripeGateway extends AbstractGateway
{
    private const API = 'https://api.stripe.com/v1';

    public function code(): string
    {
        return 'stripe';
    }

    public function name(): string
    {
        return 'Stripe';
    }

    public function credentialFields(): array
    {
        return [
            'publishable_key' => ['label' => 'Publishable key', 'secret' => false, 'required' => false, 'help' => 'pk_test_… / pk_live_…'],
            'secret_key' => ['label' => 'Secret key', 'secret' => true, 'help' => 'sk_test_… / sk_live_…'],
            'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true, 'required' => false, 'help' => 'whsec_…. Subscribe to checkout.session.completed and charge.refunded.'],
        ];
    }

    public function createPayment(Order $order, array $urls): PaymentAction
    {
        $response = Http::withToken($this->cred('secret_key'))->asForm()->timeout(20)
            ->post(self::API.'/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $order->number,
                'customer_email' => $order->user?->email,
                'success_url' => $urls['return'].(str_contains($urls['return'], '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $urls['cancel'],
                'metadata' => ['order' => $order->number],
                'payment_intent_data' => ['metadata' => ['order' => $order->number]],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $order->amountMinor(),
                        'product_data' => ['name' => $order->lines[0]['description'] ?? $order->number],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Stripe: '.($response->json('error.message') ?? 'could not create checkout session'));
        }

        return PaymentAction::redirect($response->json('url'), $response->json('id'));
    }

    public function verifyReturn(Order $order, Request $request): PaymentResult
    {
        $sessionId = (string) $request->query('session_id');
        if ($sessionId !== $order->gateway_order_id) {
            return PaymentResult::failed('Checkout session does not match this order.');
        }

        $session = Http::withToken($this->cred('secret_key'))->timeout(20)->get(self::API."/checkout/sessions/$sessionId");

        if ($session->json('payment_status') === 'paid' && $session->json('client_reference_id') === $order->number) {
            return PaymentResult::paid((string) $session->json('payment_intent'));
        }

        return PaymentResult::failed('Payment has not been completed.');
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        if (! self::validSignature($request->getContent(), (string) $request->header('Stripe-Signature'), (string) $this->cred('webhook_secret'))) {
            abort(400, 'Invalid Stripe signature');
        }

        $type = $request->input('type');
        $object = $request->input('data.object', []);

        return match ($type) {
            'checkout.session.completed' => ($object['payment_status'] ?? null) === 'paid'
                ? new WebhookResult($request->input('id'), $type, gatewayOrderId: $object['id'] ?? null, orderNumber: $object['client_reference_id'] ?? null, paid: true, paymentId: $object['payment_intent'] ?? null)
                : null,
            'charge.refunded' => new WebhookResult($request->input('id'), $type, orderNumber: $object['metadata']['order'] ?? null, refunded: true),
            default => null,
        };
    }

    /** Stripe-Signature: t=timestamp,v1=hmac_sha256("{t}.{payload}") — 5 minute tolerance. */
    public static function validSignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = collect(explode(',', $header))->mapWithKeys(function ($p) {
            [$k, $v] = array_pad(explode('=', trim($p), 2), 2, '');

            return [$k => $v];
        });
        $t = (int) $parts->get('t');
        $v1 = (string) $parts->get('v1');

        return $t > 0 && abs(time() - $t) <= $tolerance
            && hash_equals(hash_hmac('sha256', $t.'.'.$payload, $secret), $v1);
    }
}
