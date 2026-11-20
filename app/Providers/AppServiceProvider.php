<?php

namespace App\Providers;

use App\Services\Billing\BillingClient;
use App\Services\Billing\HttpBillingClient;
use App\Services\Billing\LocalBillingClient;
use App\Services\Currency\CurrencyService;
use App\Support\Settings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(CurrencyService::class);

        $this->app->singleton(BillingClient::class, fn () => config('services.wcc.enabled')
            ? new HttpBillingClient(config('services.wcc'))
            : new LocalBillingClient);
    }

    public function boot(): void
    {
        //
    }
}
