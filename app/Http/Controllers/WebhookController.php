<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WebhookEvent;
use App\Payments\GatewayManager;
use App\Services\Orders\OrderFulfiller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $fulfiller->refund($order, ['reference' => $result->paymentId, 'reason' => 'Refunded in '.$gateway], 'gateway webhook: '.$gateway);
        } elseif (! $order) {
            Log::warning('Webhook for unknown order', ['gateway' => $gateway, 'event' => $result->eventId]);
        }

        $event->update(['processed_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
