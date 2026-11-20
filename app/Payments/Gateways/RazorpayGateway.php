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
 * Razorpay: Orders API + Standard Checkout (checkout.js).
 * Docs: https://razorpay.com/docs/payments/payment-gateway/web-integration/standard/
 */
class RazorpayGateway extends AbstractGateway
{
    private const API = 'https://api.razorpay.com/v1';

    public function code(): string
    {
        return 'razorpay';
    }

    public function name(): string
    {
        return 'Razorpay';
    }

    public function credentialFields(): array
    {
        return [
            'key_id' => ['label' => 'Key ID', 'secret' => false, 'help' => 'rzp_test_… or rzp_live_… from Dashboard → Account & Settings → API keys.'],
            'key_secret' => ['label' => 'Key secret', 'secret' => true],
            'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true, 'required' => false, 'help' => 'Set the same secret on the webhook in the Razorpay dashboard. Subscribe to payment.captured, order.paid and refund.processed.'],
        ];
    }

    public function createPayment(Order $order, array $urls): PaymentAction
    {
        $response = Http::withBasicAuth($this->cred('key_id'), $this->cred('key_secret'))
            ->acceptJson()->timeout(20)
            ->post(self::API.'/orders', [
                'amount' => $order->amountMinor(),
                'currency' => $order->currency,
                'receipt' => $order->number,
                'notes' => ['order' => $order->number, 'organisation' => $order->organisation->name],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Razorpay: '.($response->json('error.description') ?? 'could not create order'));
        }

        $rzpOrderId = $response->json('id');

        return PaymentAction::script([
            'key' => $this->cred('key_id'),
            'order_id' => $rzpOrderId,
            'amount' => $order->amountMinor(),
            'currency' => $order->currency,
            'name' => setting('branding.site_name'),
            'description' => $order->lines[0]['description'] ?? $order->number,
            'prefill' => ['name' => $order->user?->name, 'email' => $order->user?->email],
            'notes' => ['order' => $order->number],
            'theme' => ['color' => setting('branding.color_primary')],
            'callback' => $urls['verify'],
            'cancel' => $urls['cancel'],
        ], $rzpOrderId);
    }

    /** Checkout handler posts razorpay_order_id, razorpay_payment_id, razorpay_signature. */
    public function verifyReturn(Order $order, Request $request): PaymentResult
    {
        $orderId = (string) $request->input('razorpay_order_id');
        $paymentId = (string) $request->input('razorpay_payment_id');
        $signature = (string) $request->input('razorpay_signature');

        if ($orderId !== $order->gateway_order_id || ! $paymentId || ! $signature) {
            return PaymentResult::failed('Payment details are missing or do not match this order.');
        }

        return self::validSignature($orderId.'|'.$paymentId, $signature, (string) $this->cred('key_secret'))
            ? PaymentResult::paid($paymentId)
            : PaymentResult::failed('Payment signature could not be verified.');
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $secret = $this->cred('webhook_secret');
        if (! $secret || ! self::validSignature($request->getContent(), (string) $request->header('X-Razorpay-Signature'), $secret)) {
            abort(400, 'Invalid Razorpay signature');
        }

        $event = $request->input('event');
        $eventId = $request->header('X-Razorpay-Event-Id') ?? md5($request->getContent());

        return match ($event) {
            'payment.captured', 'order.paid' => new WebhookResult(
                eventId: $eventId, type: $event,
                gatewayOrderId: $request->input('payload.payment.entity.order_id') ?? $request->input('payload.order.entity.id'),
                paid: true,
                paymentId: $request->input('payload.payment.entity.id'),
            ),
            'refund.processed' => new WebhookResult(
                eventId: $eventId, type: $event,
                gatewayOrderId: $request->input('payload.payment.entity.order_id'),
                refunded: true,
            ),
            default => null,
        };
    }

    public static function validSignature(string $payload, string $signature, string $secret): bool
    {
        return $signature !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
