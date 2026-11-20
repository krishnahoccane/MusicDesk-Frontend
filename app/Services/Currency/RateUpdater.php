<?php

namespace App\Services\Currency;

use App\Models\Currency;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Pulls USD-based exchange rates from a free public provider. Runs daily via the scheduler. */
class RateUpdater
{
    public const PROVIDERS = [
        'open_er_api' => 'https://open.er-api.com/v6/latest/USD',
        'frankfurter' => 'https://api.frankfurter.dev/v1/latest?base=USD',
    ];

    /** @return array<string, float> rates per 1 USD */
    public function fetch(?string $provider = null): array
    {
        $provider = $provider ?? setting('general.rates_provider', 'open_er_api');
        $url = self::PROVIDERS[$provider] ?? self::PROVIDERS['open_er_api'];

        $response = Http::timeout(15)->retry(2, 500)->acceptJson()->get($url);
        $rates = $response->json('rates');

        if (! $response->successful() || ! is_array($rates) || empty($rates)) {
            throw new RuntimeException("Exchange-rate provider [$provider] returned no rates (HTTP {$response->status()}).");
        }

        return array_map('floatval', $rates);
    }

    /** Update every auto-updating currency. Returns [code => rate] that changed. */
    public function update(?string $provider = null): array
    {
        $rates = $this->fetch($provider);
        $updated = [];

        Currency::query()->where('auto_update', true)->where('code', '!=', 'USD')->each(function (Currency $c) use ($rates, &$updated) {
            if (! isset($rates[$c->code]) || $rates[$c->code] <= 0) {
                Log::warning("No exchange rate for {$c->code}; keeping {$c->rate}.");

                return;
            }
            $c->update(['rate' => $rates[$c->code], 'rate_updated_at' => now()]);
            $updated[$c->code] = $rates[$c->code];
        });

        app(CurrencyService::class)->flush();

        return $updated;
    }
}
