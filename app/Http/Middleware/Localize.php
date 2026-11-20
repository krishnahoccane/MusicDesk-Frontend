<?php

namespace App\Http\Middleware;

use App\Services\Currency\CurrencyService;
use App\Services\Geo\GeoIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the visitor's time zone and currency for this request.
 * The app itself always runs and stores dates in UTC; this only affects display.
 *
 * Time zone:  profile → session → IP lookup → browser (tz cookie) → UTC
 * Currency:   session choice → profile → organisation → country (IP) → default (USD)
 */
class Localize
{
    public function __construct(private GeoIp $geo, private CurrencyService $currencies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $user = $request->user();

        // One IP lookup per session.
        if (! $session->has('geo')) {
            $session->put('geo', setting('general.timezone_by_ip') || setting('general.currency_by_country')
                ? ($this->geo->lookup($request->ip()) ?? [])
                : []);
        }
        $geo = $session->get('geo', []);

        $timezone = $this->validTz($user?->timezone)
            ?? $this->validTz($session->get('timezone'))
            ?? (setting('general.timezone_by_ip') ? $this->validTz($geo['timezone'] ?? null) : null)
            ?? $this->validTz($request->cookie('tz'))
            ?? 'UTC';

        $country = $user?->country ?? ($geo['country'] ?? null);
        $byCountry = setting('general.currency_by_country') && $country ? (config('site.country_currency')[$country] ?? null) : null;

        $currency = collect([
            $session->get('currency'),
            $user?->currency,
            $user?->organisation?->currency !== 'USD' ? $user?->organisation?->currency : null,
            $byCountry,
            setting('general.default_currency', 'USD'),
        ])->first(fn ($c) => $c && $this->currencies->find($c)) ?? 'USD';

        app()->instance('locale.timezone', $timezone);
        app()->instance('locale.currency', $currency);
        app()->instance('locale.country', $country);

        View::share('localeTimezone', $timezone);
        View::share('localeCurrency', $currency);

        return $next($request);
    }

    private function validTz(?string $tz): ?string
    {
        return $tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : null;
    }
}
