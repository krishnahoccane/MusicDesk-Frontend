<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Looks up country, time zone and currency for an IP address.
 * Results are cached for 30 days per IP. Private / local IPs return null.
 */
class GeoIp
{
    /** @return array{country: ?string, timezone: ?string, currency: ?string}|null */
    public function lookup(?string $ip): ?array
    {
        $provider = setting('general.geoip_provider', 'ipapi');

        if (! $ip || $provider === 'none' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return Cache::remember("geoip.$provider.".md5($ip), now()->addDays(30), function () use ($ip, $provider) {
            try {
                return match ($provider) {
                    'ipwhois' => $this->ipwhois($ip),
                    default => $this->ipapi($ip),
                };
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private function ipapi(string $ip): ?array
    {
        $r = Http::timeout(3)->acceptJson()->withUserAgent('musicdesk-geo/1.0')->get("https://ipapi.co/$ip/json/");
        if (! $r->successful() || $r->json('error')) {
            return null;
        }

        return $this->normalize($r->json('country_code'), $r->json('timezone'), $r->json('currency'));
    }

    private function ipwhois(string $ip): ?array
    {
        $r = Http::timeout(3)->acceptJson()->get("https://ipwho.is/$ip");
        if (! $r->successful() || $r->json('success') === false) {
            return null;
        }

        return $this->normalize($r->json('country_code'), $r->json('timezone.id'), $r->json('currency.code'));
    }

    private function normalize(?string $country, ?string $tz, ?string $currency): array
    {
        return [
            'country' => $country ? strtoupper($country) : null,
            'timezone' => in_array($tz, timezone_identifiers_list(), true) ? $tz : null,
            'currency' => $currency ? strtoupper($currency) : null,
        ];
    }
}
