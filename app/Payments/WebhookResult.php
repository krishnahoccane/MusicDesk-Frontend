<?php

namespace App\Payments;

final class WebhookResult
{
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $gatewayOrderId = null,   // our orders.gateway_order_id
        public ?string $orderNumber = null,      // or our orders.number
        public bool $paid = false,
        public ?string $paymentId = null,
        public bool $refunded = false,
    ) {}
}
