<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\LicenseAddon;
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
 *  addon            a licensed add-on for this license: annual list price (one year) or lifetime price
 *
 * Add-on licenses offer renewal (annual) or updates renewal (lifetime) at the add-on's prices,
 * unless the add-on is included with the plan (then it follows the main license).
 */
class LicensePricer
{
    public const UPDATES_RENEWAL_SHARE = 0.6;

    public function __construct(private CurrencyService $currencies, private OrderPricer $orders) {}

    /** Which purchases this license offers right now: kind => why-not message or null when available. */
    public function options(License $license): array
    {
        if ($license->isAddon()) {
            return $this->addonOptions($license);
        }
        if ($license->isTrial()) {   // a trial is turned into a paid license by buying the plan (checkout)
            return array_fill_keys(['renewal', 'updates_renewal', 'extra_domains', 'change_plan', 'convert_lifetime', 'addon'],
                'This is a free trial. Buy the '.($license->plan?->name ?? '').' plan to keep this key: the trial becomes your license.');
        }
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
            'addon' => $blocked ?? ($this->availableAddons($license)->isNotEmpty() ? null : 'No more add-ons are available for this license.'),
        ];
    }

    /** Active add-ons this main license doesn't have yet. */
    public function availableAddons(License $license)
    {
        $owned = $license->addonLicenses()->where('status', '!=', License::REVOKED)->pluck('addon_id')->all();

        return LicenseAddon::active()->whereNotIn('id', $owned)->get();
    }

    private function addonOptions(License $license): array
    {
        $no = 'Not available for add-ons.';
        $blocked = in_array($license->status, [License::SUSPENDED, License::REVOKED], true) ? 'This add-on is '.$license->status.'.' : null;
        $included = $license->included ? 'Included with your plan. It renews with '.$license->parent?->number.'.' : null;
        $annual = $license->billing_type === 'annual';

        return [
            'renewal' => $blocked ?? $included ?? ($annual ? null : 'Lifetime add-ons don’t renew.'),
            'updates_renewal' => $blocked ?? $included ?? ($annual ? 'Annual add-ons include updates while renewed.' : ($license->addon?->price_lifetime ? null : 'Contact sales.')),
            'extra_domains' => 'An add-on runs on its main license’s domains.',
            'change_plan' => $no,
            'convert_lifetime' => $no,
            'addon' => $no,
        ];
    }

    /**
     * @param  array{quantity?: int, plan?: ?Plan, addon?: ?LicenseAddon, billing?: ?string}  $input
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
        $addonId = null;
        $billing = $license->billing_type;
        // An add-on license renews at the add-on's prices.
        $list = $license->isAddon()
            ? ['name' => $license->addon->name.' add-on', 'annual' => $license->addon->price_annual, 'lifetime' => $license->addon->price_lifetime]
            : ['name' => $plan->name, 'annual' => $plan->price_annual, 'lifetime' => $plan->price_lifetime];
        $price = fn (float $usd) => $this->currencies->price(max(0, $usd), $currency)['amount'];
        $daysLeft = max(0, (int) ($license->expires_at ? floor(now()->diffInDays($license->expires_at, false)) : 0));
        $share = $license->billing_type === 'annual' ? min(1, $daysLeft / 365) : 1;
        $lines = [];
        $planId = $plan->id;
        $extra = 0;

        switch ($kind) {
            case 'renewal':
                $lines[] = ['code' => 'renewal', 'description' => "$product {$list['name']}: annual renewal ({$license->number})", 'quantity' => 1, 'unit' => $price($list['annual']), 'amount' => $price($list['annual'])];
                if ($license->domains_extra > 0 && ! $license->isAddon()) {
                    $unit = $price($plan->extra_domain_price);
                    $lines[] = ['code' => 'extra_domain', 'description' => 'Extra production domains (1 year)', 'quantity' => $license->domains_extra, 'unit' => $unit, 'amount' => $unit * $license->domains_extra];
                }
                break;

            case 'updates_renewal':
                $amount = $price($list['lifetime'] * self::UPDATES_RENEWAL_SHARE);
                $lines[] = ['code' => 'updates_renewal', 'description' => "$product {$list['name']}: 12 more months of updates & support ({$license->number})", 'quantity' => 1, 'unit' => $amount, 'amount' => $amount];
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

            case 'addon':
                $addon = $input['addon'] ?? null;
                if (! $addon instanceof LicenseAddon || ! $this->availableAddons($license)->contains('id', $addon->id)) {
                    throw new LicenseException('invalid_addon', 'Choose an add-on this license doesn’t have yet.', 422, 'addon');
                }
                $billing = ($input['billing'] ?? 'annual') === 'lifetime' ? 'lifetime' : 'annual';
                $usd = $addon->usdPrice($billing);
                if ($usd === null) {
                    throw new LicenseException('invalid_billing', "{$addon->name} isn’t sold as lifetime online.", 422, 'billing');
                }
                $amount = $price($usd);
                $lines[] = ['code' => 'addon', 'description' => "$product {$addon->name} add-on: ".($billing === 'lifetime' ? 'lifetime (12 months of updates)' : 'annual')." for {$license->number}", 'quantity' => 1, 'unit' => $amount, 'amount' => $amount];
                $addonId = $addon->id;
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
            'coupon' => null, 'plan_id' => $planId, 'extra_domains' => $extra, 'addon_id' => $addonId, 'billing_type' => $kind === 'convert_lifetime' ? 'lifetime' : $billing,
        ];
    }
}
