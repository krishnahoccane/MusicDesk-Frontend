<?php

namespace App\Services\Orders;

use App\Models\Addon;
use App\Models\Plan;
use App\Services\Currency\CurrencyService;
use InvalidArgumentException;

/**
 * Server-side price calculation for a license order in the customer's currency.
 * Never trust totals from the browser; this is the source of truth.
 */
class OrderPricer
{
    public function __construct(private CurrencyService $currencies) {}

    /**
     * @param  string[]  $addonCodes
     * @return array{currency:string, rate:float, lines:array, subtotal:float, discount:float, tax_rate:float, tax:float, total:float, coupon:?string}
     */
    public function quote(Plan $plan, string $billingType, int $extraDomains, array $addonCodes, string $currency, ?string $country = null, ?string $vatId = null, ?string $coupon = null): array
    {
        $usd = $plan->usdPrice($billingType);
        if ($usd === null) {
            throw new InvalidArgumentException("The {$plan->name} plan is not sold as a {$billingType} license online.");
        }

        $price = fn (float $usdAmount) => $this->currencies->price($usdAmount, $currency)['amount'];
        $label = "{$plan->name}: ".($billingType === 'lifetime' ? 'lifetime license' : 'annual license');

        $lines = [[
            'code' => 'license', 'description' => setting('branding.product_name')." $label",
            'quantity' => 1, 'unit' => $price($usd), 'amount' => $price($usd), 'discountable' => true,
        ]];

        if ($extraDomains > 0) {
            $unit = $price($plan->usdExtraDomainPrice($billingType));
            $lines[] = ['code' => 'extra_domain', 'description' => 'Extra production domain'.($billingType === 'lifetime' ? ' (lifetime)' : ' (1 year)'),
                'quantity' => $extraDomains, 'unit' => $unit, 'amount' => $unit * $extraDomains, 'discountable' => true];
        }

        foreach (Addon::active()->whereIn('code', $addonCodes)->where('checkout', true)->get() as $addon) {
            $lines[] = ['code' => $addon->code, 'description' => $addon->name, 'quantity' => 1,
                'unit' => $price($addon->price), 'amount' => $price($addon->price), 'discountable' => false];
        }

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);

        $percent = $coupon ? $this->couponPercent($coupon) : null;
        $discountable = array_sum(array_column(array_filter($lines, fn ($l) => $l['discountable']), 'amount'));
        $discount = $percent ? round($discountable * $percent / 100, 2) : 0.0;

        $taxRate = $this->taxRate($country, $vatId);
        $tax = round(($subtotal - $discount) * $taxRate, 2);

        return [
            'currency' => $currency,
            'rate' => $this->currencies->rate($currency),
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total' => round($subtotal - $discount + $tax, 2),
            'coupon' => $percent ? strtoupper($coupon) : null,
        ];
    }

    /** Coupons are managed in /admin/plans as "CODE=PERCENT" lines. */
    public function couponPercent(string $code): ?float
    {
        foreach (preg_split('/\R/', (string) setting('pricing.coupons')) as $line) {
            [$c, $p] = array_pad(explode('=', trim($line), 2), 2, null);
            if ($c && strcasecmp(trim($c), trim($code)) === 0 && is_numeric($p)) {
                return min(100, max(0, (float) $p));
            }
        }

        return null;
    }

    /** Tax by buyer country; B2B buyers abroad with a tax ID are zero-rated (reverse charge / export). */
    public function taxRate(?string $country, ?string $vatId): float
    {
        $seller = strtoupper((string) setting('invoice.seller_country', 'US'));
        $country = strtoupper((string) $country);

        if ($vatId && $country && $country !== $seller) {
            return 0.0;
        }

        foreach (preg_split('/\R/', (string) setting('invoice.tax_rules')) as $line) {
            [$c, $p] = array_pad(explode('=', trim($line), 2), 2, null);
            if ($c && strtoupper(trim($c)) === $country && is_numeric($p)) {
                return (float) $p / 100;
            }
        }

        return (float) setting('invoice.tax_rate', 0) / 100;
    }
}
