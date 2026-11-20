<?php

namespace App\Services\Currency;

use App\Models\Currency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Converts USD list prices into the visitor's currency.
 *
 * Charm pricing rounds the converted amount DOWN to a price ending in 9s,
 * so the customer always sees a small discount versus the exact conversion:
 *   499 USD × 92.64 INR = 46,227.36 → ₹45,999
 *   499 USD × 0.76 GBP  =    379.24 → £379
 *   499 USD × 0.91 EUR  =    454.09 → €449
 */
class CurrencyService
{
    public const BASE = 'USD';

    /** @var Collection<string, Currency>|null per-request memo (the table is tiny) */
    private ?Collection $enabled = null;

    /** @return Collection<string, Currency> */
    public function enabled(): Collection
    {
        return $this->enabled ??= Currency::enabled()->get()->keyBy('code');
    }

    public function find(string $code): ?Currency
    {
        return $this->enabled()->get(strtoupper($code));
    }

    /** Visitor's active currency (resolved by the Localize middleware). */
    public function current(): string
    {
        $code = app()->bound('locale.currency') ? app('locale.currency') : setting('general.default_currency', self::BASE);

        return $this->find($code) ? $code : self::BASE;
    }

    public function rate(string $code): float
    {
        return $this->find($code)?->effectiveRate() ?? 1.0;
    }

    /**
     * Round DOWN to a "charm" price ending in 9s, using as many trailing 9s as possible
     * while the discount stays within $maxDiscountPct:
     *   46,227.36 → 45,999   (3 nines, −0.5%)
     *   13,803.36 → 13,799   (3 nines would be −5.8%, so 2 nines)
     *      454.09 →    449   ·   379.24 → 379
     */
    public static function charm(float $value, float $maxDiscountPct = 3.0): float
    {
        if ($value < 10) {
            return floor($value * 100) / 100;
        }

        $digits = strlen((string) (int) floor($value));
        for ($nines = $digits - 1; $nines >= 1; $nines--) {
            $step = 10 ** $nines;
            $rounded = floor(($value + 1) / $step) * $step - 1;
            if ($rounded > 0 && ($value - $rounded) / $value * 100 <= $maxDiscountPct) {
                return (float) $rounded;
            }
        }

        // Nothing ending in 9 fits the cap: drop the decimals if that still fits, otherwise keep the exact price.
        return ($value - floor($value)) / $value * 100 <= $maxDiscountPct ? floor($value) : round($value, 2);
    }

    /** Price a USD amount in a currency. */
    public function price(float $usd, ?string $code = null): array
    {
        $code = strtoupper($code ?? $this->current());
        $currency = $this->find($code);
        $code = $currency ? $code : self::BASE;
        $rate = $this->rate($code);
        $exact = round($usd * $rate, $currency?->decimals ?? 2);

        $amount = ($code === self::BASE || ! ($currency?->charm_pricing ?? false) || $usd <= 0)
            ? $exact
            : self::charm($exact, (float) setting('general.charm_max_discount', 3));

        return [
            'currency' => $code,
            'usd' => $usd,
            'rate' => $rate,
            'amount' => $amount,
            'exact' => $exact,
            'discounted' => $amount < $exact - 0.005,
            'display' => $this->format($amount, $code),
            'exact_display' => $this->format($exact, $code, false),
        ];
    }

    public function symbol(string $code): string
    {
        return $this->find($code)?->symbol ?? $code.' ';
    }

    /** Format an amount already in $code. Whole amounts drop the decimals unless $decimals is forced. */
    public function format(float $amount, string $code, ?bool $decimals = null): string
    {
        $places = $this->find($code)?->decimals ?? 2;
        $showDecimals = $decimals ?? (fmod($amount, 1.0) !== 0.0);
        $number = number_format($amount, $showDecimals ? $places : 0);

        return $this->symbol($code).$number;
    }

    public function flush(): void
    {
        $this->enabled = null;
    }
}
