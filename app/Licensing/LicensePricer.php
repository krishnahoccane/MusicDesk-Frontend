<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\Plan;
use App\Services\Currency\CurrencyService;
use App\Services\Orders\OrderPricer;

/**
 * Prices for changing an existing license, in the customer's currency (charm-rounded like
 * every other price). All amounts start from the plan's USD list prices.
 *
 *  renewal          annual license + its extra domains, one more year
 *  updates_renewal  lifetime: 60% of the lifetime price for one more year of updates
 *  extra_domains    annual: prorated to the renewal date · lifetime: one-time 2.5× the yearly domain price
 *  change_plan      upgrade only · annual: price difference prorated · lifetime: lifetime price difference
 *  convert_lifetime lifetime price minus credit for the unused annual time
 */
class LicensePricer
{
    public const UPDATES_RENEWAL_SHARE = 0.6;

    public function __construct(private CurrencyService $currencies, private OrderPricer $orders) {}

    /** Which purchases this license offers right now: kind => why-not message or null when available. */
    public function options(License $license): array
    {
        $usable = $license->allowsUse() || $license->displayStatus() === 'expired';
        $blocked = in_array($license->status, [License::SUSPENDED, License::REVOKED], true) ? 'This license is '.$license->status.'.' : null;
        $annual = $license->billing_type === 'annual';
        $higher = Plan::active()->where('price_annual', '>', $license->plan->price_annual)->exists();

        return [
            'renewal' => $blocked ?? ($annual ? null : 'Lifetime licenses don’t renew.'),
            'updates_renewal' => $blocked ?? ($annual ? 'Annual licenses include updates while renewed.' : ($license->plan->price_lifetime ? null : 'Contact sales.')),
            'extra_domains' => $blocked ?? ($usable ? null : 'Not available.'),
            'change_plan' => $blocked ?? ($higher ? null : 'This is already the highest plan.'),
            'convert_lifetime' => $blocked ?? (! $annual ? 'Already a lifetime license.' : ($license->plan->price_lifetime ? null : 'This plan isn’t sold as lifetime online.')),
        ];
    }

    /**
     * @param  array{quantity?: int, plan?: ?Plan}  $input
     * @return array{currency:string, rate:float, lines:array, subtotal:float, discount:float, tax_rate:float, tax:float, total:float, coupon:null, plan_id:int, extra_domains:int}
     */
    public function quote(License $license, string $kind, array $input, string $currency): array
    {
        $options = $this->options($license);
        $reason = array_key_exists($kind, $options) ? $options[$kind] : 'Unknown purchase.';
        if ($reason !== null) {
            throw new LicenseException('not_available', $reason, 422);
        }

        $plan = $license->plan;
        $product = setting('branding.product_name', 'LabelDesk');
        $price = fn (float $usd) => $this->currencies->price(max(0, $usd), $currency)['amount'];
        $daysLeft = max(0, (int) ($license->expires_at ? floor(now()->diffInDays($license->expires_at, false)) : 0));
        $share = $license->billing_type === 'annual' ? min(1, $daysLeft / 365) : 1;
        $lines = [];
        $planId = $plan->id;
        $extra = 0;

        switch ($kind) {
            case 'renewal':
                $lines[] = ['code' => 'renewal', 'description' => "$product {$plan->name}: annual renewal ({$license->number})", 'quantity' => 1, 'unit' => $price($plan->price_annual), 'amount' => $price($plan->price_annual)];
                if ($license->domains_extra > 0) {
                    $unit = $price($plan->extra_domain_price);
                    $lines[] = ['code' => 'extra_domain', 'description' => 'Extra production domains (1 year)', 'quantity' => $license->domains_extra, 'unit' => $unit, 'amount' => $unit * $license->domains_extra];
                }
                break;

            case 'updates_renewal':
                $amount = $price($plan->price_lifetime * self::UPDATES_RENEWAL_SHARE);
                $lines[] = ['code' => 'updates_renewal', 'description' => "$product {$plan->name}: 12 more months of updates & support ({$license->number})", 'quantity' => 1, 'unit' => $amount, 'amount' => $amount];
                break;

            case 'extra_domains':
                $extra = max(1, min(50, (int) ($input['quantity'] ?? 1)));
                if ($license->billing_type === 'annual') {
                    $unit = $price($plan->extra_domain_price * max($share, 1 / 365));
                    $desc = "Extra production domain, prorated to {$license->expires_at?->format('M j, Y')} ({$license->number})";
                } else {
                    $unit = $price($plan->usdExtraDomainPrice('lifetime'));
                    $desc = "Extra production domain, lifetime ({$license->number})";
                }
                $lines[] = ['code' => 'extra_domains', 'description' => $desc, 'quantity' => $extra, 'unit' => $unit, 'amount' => $unit * $extra];
                break;

            case 'change_plan':
                $to = $input['plan'] ?? null;
                if (! $to instanceof Plan || ! $to->active || $to->price_annual <= $plan->price_annual) {
                    throw new LicenseException('invalid_plan', 'Choose a higher plan to upgrade to.', 422, 'plan');
                }
                if ($license->billing_type === 'lifetime' && ! $to->price_lifetime) {
                    throw new LicenseException('invalid_plan', "The {$to->name} plan isn’t sold as lifetime online. Contact sales.", 422, 'plan');
                }
                $usd = $license->billing_type === 'annual'
                    ? ($to->price_annual - $plan->price_annual) * max($share, 1 / 365)
                    : $to->price_lifetime - ($plan->price_lifetime ?? 0);
                $amount = $price($usd);
                $lines[] = ['code' => 'change_plan', 'description' => "Upgrade {$license->number} from {$plan->name} to {$to->name}".($license->billing_type === 'annual' ? ' (prorated)' : ''), 'quantity' => 1, 'unit' => $amount, 'amount' => $amount];
                $planId = $to->id;
                break;

            case 'convert_lifetime':
                $credit = $plan->price_annual * $share;
                $amount = $price(max(0, $plan->price_lifetime - $credit));
                $lines[] = ['code' => 'convert_lifetime', 'description' => "Switch {$license->number} to a {$plan->name} lifetime license (credit for unused annual time applied)", 'quantity' => 1, 'unit' => $amount, 'amount' => $amount];
                break;
        }

        foreach ($lines as &$l) {
            $l['discountable'] = false;
        }
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $org = $license->organisation;
        $taxRate = $this->orders->taxRate($org->country, $org->vat_id);
        $tax = round($subtotal * $taxRate, 2);

        return [
            'currency' => $currency, 'rate' => $this->currencies->rate($currency), 'lines' => $lines,
            'subtotal' => $subtotal, 'discount' => 0.0, 'tax_rate' => $taxRate, 'tax' => $tax, 'total' => round($subtotal + $tax, 2),
            'coupon' => null, 'plan_id' => $planId, 'extra_domains' => $extra,
        ];
    }
}
