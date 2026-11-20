<?php

namespace App\Payments;

final class PaymentResult
{
    public function __construct(
        public bool $paid,
        public ?string $paymentId = null,
        public ?string $message = null,
    ) {}

    public static function paid(string $paymentId): self
    {
        return new self(true, $paymentId);
    }

    public static function failed(string $message): self
    {
        return new self(false, null, $message);
    }
}
