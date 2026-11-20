<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WebhookEvent;
use App\Payments\GatewayManager;
use App\Services\Orders\OrderFulfiller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /** POST /webhooks/payments/{gateway}: Razorpay, Stripe, PayPal… */
    public function gateway(Request $request, string $gateway, GatewayManager $gateways, OrderFulfiller $fulfiller): JsonResponse
    {
        abort_unless(isset(GatewayManager::DRIVERS[$gateway]), 404);

        $result = $gateways->driver($gateway)->handleWebhook($request);   // verifies signature (aborts 400 if invalid)
        if (! $result) {
            return response()->json(['ignored' => true]);
        }

        $event = WebhookEvent::firstOrCreate(['source' => $gateway, 'event_id' => $result->eventId], ['type' => $result->type, 'payload' => $request->all()]);
        if ($event->processed_at) {
            return response()->json(['duplicate' => true]);
        }

        $order = Order::query()
            ->where('gateway', $gateway)
            ->where(fn ($q) => $q->when($result->gatewayOrderId, fn ($q) => $q->orWhere('gateway_order_id', $result->gatewayOrderId))
                ->when($result->orderNumber, fn ($q) => $q->orWhere('number', $result->orderNumber)))
            ->first();

        if ($order && $result->paid) {
            $fulfiller->markPaid($order, $result->paymentId);
        } elseif ($order && $result->refunded) {
            $fulfiller->refund($order);
        } elseif (! $order) {
            Log::warning('Webhook for unknown order', ['gateway' => $gateway, 'event' => $result->eventId]);
        }

        $event->update(['processed_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /webhooks/wcc: events from billing.twsd.me (license suspended, key issued, …).
     * Header: WCC-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "{t}.{body}")>
     */
    public function wcc(Request $request): JsonResponse
    {
        abort_unless(self::validWccSignature($request->getContent(), (string) $request->header('WCC-Signature'), (string) config('services.wcc.webhook_secret')), 400, 'Invalid signature');

        // Read the signed raw body (not form input), whatever Content-Type was sent.
        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload) && filled($payload['id'] ?? null), 400, 'Invalid payload');

        $event = WebhookEvent::firstOrCreate(['source' => 'wcc', 'event_id' => (string) $payload['id']], ['type' => $payload['type'] ?? null, 'payload' => $payload]);
        if ($event->processed_at) {
            return response()->json(['duplicate' => true]);
        }

        // Events for another storefront are acknowledged and ignored.
        if (($sf = $payload['storefront'] ?? null) && $sf !== config('services.wcc.storefront')) {
            $event->update(['processed_at' => now()]);

            return response()->json(['ignored' => true]);
        }

        // license.created / updated / suspended / revoked / expiring / expired, activation.created / deactivated:
        // drop the cached licenses so the portal shows billing's current state on the next view.
        if ($customerId = $payload['customer_id'] ?? null) {
            if ($org = \App\Models\Organisation::query()->where('billing_org_id', $customerId)->first()) {
                app(\App\Services\Billing\BillingClient::class)->forget($org);
            }
        }

        $event->update(['processed_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public static function validWccSignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        parse_str(str_replace(',', '&', $header), $parts);
        $t = (int) ($parts['t'] ?? 0);

        return $t > 0 && abs(time() - $t) <= $tolerance && hash_equals(hash_hmac('sha256', $t.'.'.$payload, $secret), (string) ($parts['v1'] ?? ''));
    }
}
