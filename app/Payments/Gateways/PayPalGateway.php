<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\PaymentAction;
use App\Payments\PaymentResult;
use App\Payments\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal: Orders v2 with redirect approval, captured on return.
 * Docs: https://developer.paypal.com/docs/api/orders/v2/
 */
class PayPalGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'paypal';
    }

    public function name(): string
    {
        return 'PayPal';
    }

    public function credentialFields(): array
    {
        return [
            'client_id' => ['label' => 'Client ID', 'secret' => false],
            'client_secret' => ['label' => 'Client secret', 'secret' => true],
            'webhook_id' => ['label' => 'Webhook ID', 'secret' => false, 'required' => false, 'help' => 'From your app’s webhook settings. Subscribe to PAYMENT.CAPTURE.COMPLETED and PAYMENT.CAPTURE.REFUNDED.'],
        ];
    }

    /** PayPal does not settle every currency (e.g. INR for cross-border). */
    public function supportedCurrencies(): ?array
    {
        return ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'SGD', 'NZD', 'MXN', 'BRL', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'HKD', 'CZK', 'HUF', 'ILS', 'PHP', 'THB', 'TWD'];
    }

    private function base(): string
    {
        return $this->live() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function token(): string
    {
        return Cache::remember('paypal.token.'.md5($this->cred('client_id').$this->config->mode), 3000, function () {
            $r = Http::withBasicAuth($this->cred('client_id'), $this->cred('client_secret'))->asForm()->timeout(20)
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (! $r->successful()) {
                throw new RuntimeException('PayPal: authentication failed');
            }

            return $r->json('access_token');
        });
    }

    public function createPayment(Order $order, array $urls): PaymentAction
    {
        $decimals = $order->currency === 'JPY' ? 0 : 2;
        $r = Http::withToken($this->token())->acceptJson()->timeout(20)
            ->withHeaders(['PayPal-Request-Id' => $order->number])
            ->post($this->base().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $order->number,
                    'custom_id' => $order->number,
                    'description' => mb_substr($order->lines[0]['description'] ?? $order->number, 0, 127),
                    'amount' => ['currency_code' => $order->currency, 'value' => $this->decimalAmount($order->total, $decimals)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => setting('branding.site_name'),
                    'user_action' => 'PAY_NOW',
                    'return_url' => $urls['return'],
                    'cancel_url' => $urls['cancel'],
                ]]],
            ]);

        $approve = collect($r->json('links', []))->firstWhere('rel', 'payer-action')['href'] ?? null;
        if (! $r->successful() || ! $approve) {
            throw new RuntimeException('PayPal: '.($r->json('message') ?? 'could not create order'));
        }

        return PaymentAction::redirect($approve, $r->json('id'));
    }

    /** PayPal returns ?token={order id}; capture it now. */
    public function verifyReturn(Order $order, Request $request): PaymentResult
    {
        if ((string) $request->query('token') !== $order->gateway_order_id) {
            return PaymentResult::failed('PayPal order does not match.');
        }

        $r = Http::withToken($this->token())->acceptJson()->timeout(20)
            ->withHeaders(['PayPal-Request-Id' => $order->number.'-capture'])
            ->withBody('{}', 'application/json')
            ->post($this->base()."/v2/checkout/orders/{$order->gateway_order_id}/capture");

        if ($r->json('status') === 'COMPLETED') {
            return PaymentResult::paid((string) $r->json('purchase_units.0.payments.captures.0.id'));
        }

        return PaymentResult::failed('PayPal payment was not completed.');
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        $verify = Http::withToken($this->token())->acceptJson()->timeout(20)
            ->post($this->base().'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $this->cred('webhook_id'),
                'webhook_event' => $request->json()->all(),
            ]);
        if ($verify->json('verification_status') !== 'SUCCESS') {
            abort(400, 'Invalid PayPal signature');
        }

        $type = $request->input('event_type');
        $orderNumber = $request->input('resource.custom_id');

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => new WebhookResult($request->input('id'), $type,
                gatewayOrderId: $request->input('resource.supplementary_data.related_ids.order_id'),
                orderNumber: $orderNumber, paid: true, paymentId: $request->input('resource.id')),
            'PAYMENT.CAPTURE.REFUNDED' => new WebhookResult($request->input('id'), $type, orderNumber: $orderNumber, refunded: true),
            default => null,
        };
    }
}
