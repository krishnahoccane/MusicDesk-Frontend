<?php

namespace Tests\Unit;

use App\Payments\Gateways\RazorpayGateway;
use App\Payments\Gateways\StripeGateway;
use App\Services\Currency\CurrencyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingAndSignaturesTest extends TestCase
{
    public static function charmCases(): array
    {
        return [
            'INR example: 499 × 92.64' => [46227.36, 45999],
            'GBP example: 499 × 0.76' => [379.24, 379],
            'EUR example: 499 × 0.91' => [454.09, 449],
            'keeps within 3% (no 12,999)' => [13803.36, 13799],
            'large INR' => [120339.36, 119999],
            'GBP 4 digits' => [2659.24, 2599],
            'already ends in 9' => [499.0, 499],
            'nothing within cap: drop decimals' => [117.39, 117],
            'small amounts untouched' => [7.55, 7.55],
        ];
    }

    #[DataProvider('charmCases')]
    public function test_charm_pricing_rounds_down_to_nines(float $exact, float $expected): void
    {
        $this->assertSame((float) $expected, CurrencyService::charm($exact, 3));
    }

    public function test_charm_price_never_exceeds_the_exact_conversion(): void
    {
        foreach ([12.4, 99.99, 154.2, 999.0, 1000.0, 5555.55, 76946.0, 1234567.0] as $v) {
            $this->assertLessThanOrEqual($v, CurrencyService::charm($v));
            $this->assertGreaterThanOrEqual($v * 0.97 - 0.01, CurrencyService::charm($v));
        }
    }

    public function test_razorpay_signature(): void
    {
        $secret = 'test_secret';
        $sig = hash_hmac('sha256', 'order_123|pay_456', $secret);

        $this->assertTrue(RazorpayGateway::validSignature('order_123|pay_456', $sig, $secret));
        $this->assertFalse(RazorpayGateway::validSignature('order_123|pay_999', $sig, $secret));
        $this->assertFalse(RazorpayGateway::validSignature('order_123|pay_456', '', $secret));
    }

    public function test_stripe_signature_with_tolerance(): void
    {
        $secret = 'whsec_test';
        $payload = '{"id":"evt_1"}';
        $t = time();
        $header = "t=$t,v1=".hash_hmac('sha256', "$t.$payload", $secret);

        $this->assertTrue(StripeGateway::validSignature($payload, $header, $secret));
        $this->assertFalse(StripeGateway::validSignature($payload.'x', $header, $secret));

        $old = $t - 3600;
        $this->assertFalse(StripeGateway::validSignature($payload, "t=$old,v1=".hash_hmac('sha256', "$old.$payload", $secret), $secret));
    }

}
