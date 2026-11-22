<?php

namespace App\Providers;

use App\Services\Currency\CurrencyService;
use App\Support\Settings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(CurrencyService::class);

    }

    public function boot(): void
    {
        // License server: 30 requests a minute per IP.
        \Illuminate\Support\Facades\RateLimiter::for('license-server', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('license:'.$r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('license-report', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('license-report:'.$r->ip()));

        // Web installer: before installation, make sure .env/APP_KEY exist and keep
        // sessions, cache and queue off the (not yet configured) database.
        if (! $this->app->runningInConsole() && ! \App\Support\Installer\Installer::isInstalled()) {
            \App\Support\Installer\Installer::prepareRuntime();
        }
    }
}
