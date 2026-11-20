<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGateway as GatewayModel;
use App\Payments\PaymentGateway;
use App\Payments\WebhookResult;
use Illuminate\Http\Request;

abstract class AbstractGateway implements PaymentGateway
{
    public function __construct(protected GatewayModel $config) {}

    public function supportedCurrencies(): ?array
    {
        return null;
    }

    public function isConfigured(): bool
    {
        foreach ($this->credentialFields() as $key => $field) {
            if (($field['required'] ?? true) && blank($this->config->credential($key))) {
                return false;
            }
        }

        return true;
    }

    public function handleWebhook(Request $request): ?WebhookResult
    {
        return null;
    }

    protected function cred(string $key): ?string
    {
        return $this->config->credential($key);
    }

    protected function live(): bool
    {
        return $this->config->mode === 'live';
    }

    /** Amount as a decimal string, e.g. "45999.00" (PayPal style). */
    protected function decimalAmount(float $amount, int $decimals = 2): string
    {
        return number_format($amount, $decimals, '.', '');
    }
}
