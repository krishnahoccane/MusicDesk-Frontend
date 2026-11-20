<?php

namespace App\Payments;

use App\Models\PaymentGateway as GatewayModel;
use App\Payments\Gateways\BankTransferGateway;
use App\Payments\Gateways\PayPalGateway;
use App\Payments\Gateways\RazorpayGateway;
use App\Payments\Gateways\StripeGateway;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class GatewayManager
{
    /** code => driver class. Add new gateways here. */
    public const DRIVERS = [
        'razorpay' => RazorpayGateway::class,
        'stripe' => StripeGateway::class,
        'paypal' => PayPalGateway::class,
        'bank_transfer' => BankTransferGateway::class,
    ];

    public function driver(string $code): PaymentGateway
    {
        $class = self::DRIVERS[$code] ?? throw new InvalidArgumentException("Unknown payment gateway [$code].");
        $model = GatewayModel::find($code) ?? new GatewayModel(['code' => $code, 'name' => $code]);

        return new $class($model);
    }

    /** Every registered gateway with its stored config, for the admin screen. */
    public function all(): Collection
    {
        $models = GatewayModel::query()->orderBy('sort')->get()->keyBy('code');

        return collect(self::DRIVERS)->map(function ($class, $code) use ($models) {
            $model = $models[$code] ?? new GatewayModel(['code' => $code, 'name' => $code, 'enabled' => false]);

            return ['model' => $model, 'driver' => new $class($model)];
        });
    }

    /** Gateways a customer can pay with right now, in this currency. */
    public function availableFor(string $currency): Collection
    {
        return GatewayModel::query()->where('enabled', true)->orderBy('sort')->get()
            ->filter(fn (GatewayModel $m) => isset(self::DRIVERS[$m->code]))
            ->map(fn (GatewayModel $m) => $this->driver($m->code))
            ->filter(fn (PaymentGateway $g) => $g->isConfigured()
                && (($g->supportedCurrencies() === null) || in_array($currency, $g->supportedCurrencies(), true))
                && (GatewayModel::find($g->code())?->supportsCurrency($currency) ?? true))
            ->values();
    }
}
