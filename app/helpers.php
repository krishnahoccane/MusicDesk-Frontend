<?php

use App\Services\Currency\CurrencyService;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

if (! function_exists('setting')) {
    /** Site setting by "group.key", falling back to config/site.php defaults. */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(Settings::class)->get($key, $default);
    }
}

if (! function_exists('money')) {
    /**
     * Price a USD amount in the visitor's currency (charm-rounded).
     * Returns ['amount' => 45999.0, 'exact' => 46227.36, 'display' => '₹45,999', 'exact_display' => '₹46,227', 'currency' => 'INR', 'discounted' => true]
     */
    function money(float $usd, ?string $currency = null): array
    {
        return app(CurrencyService::class)->price($usd, $currency);
    }
}

if (! function_exists('fmt_money')) {
    /** Format an amount that is already in $currency (e.g. stored invoice totals). */
    function fmt_money(float $amount, string $currency): string
    {
        return app(CurrencyService::class)->format($amount, $currency);
    }
}

if (! function_exists('local_time')) {
    /** Convert a UTC timestamp to the visitor's time zone (detected by IP / profile). */
    function local_time(CarbonInterface|string|null $time): ?Carbon
    {
        if ($time === null) {
            return null;
        }

        return Carbon::parse($time, 'UTC')->setTimezone(user_timezone());
    }
}

if (! function_exists('user_timezone')) {
    function user_timezone(): string
    {
        return app()->bound('locale.timezone') ? app('locale.timezone') : 'UTC';
    }
}
