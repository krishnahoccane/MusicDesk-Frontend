<?php

namespace App\Payments;

/** What the browser should do next to complete a payment. */
final class PaymentAction
{
    public const REDIRECT = 'redirect';   // send the customer to a hosted payment page

    public const SCRIPT = 'script';       // open a JS checkout widget (e.g. Razorpay) with $payload

    public const OFFLINE = 'offline';     // no online payment (bank transfer); invoice is issued unpaid

    public function __construct(
        public string $type,
        public ?string $redirectUrl = null,
        public array $payload = [],
        public ?string $gatewayOrderId = null,
    ) {}

    public static function redirect(string $url, ?string $gatewayOrderId = null): self
    {
        return new self(self::REDIRECT, $url, [], $gatewayOrderId);
    }

    public static function script(array $payload, ?string $gatewayOrderId = null): self
    {
        return new self(self::SCRIPT, null, $payload, $gatewayOrderId);
    }

    public static function offline(array $payload = []): self
    {
        return new self(self::OFFLINE, null, $payload);
    }
}
