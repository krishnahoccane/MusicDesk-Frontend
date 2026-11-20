<?php

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Payments\PaymentAction;
use App\Payments\PaymentResult;
use Illuminate\Http\Request;

/** Offline bank transfer / wire. The invoice is issued unpaid; an admin marks it paid. */
class BankTransferGateway extends AbstractGateway
{
    public function code(): string
    {
        return 'bank_transfer';
    }

    public function name(): string
    {
        return 'Bank transfer';
    }

    public function credentialFields(): array
    {
        return [
            'account_name' => ['label' => 'Account name', 'secret' => false],
            'bank_name' => ['label' => 'Bank name', 'secret' => false],
            'account_number' => ['label' => 'Account number / IBAN', 'secret' => false],
            'routing' => ['label' => 'SWIFT / IFSC / routing code', 'secret' => false],
            'instructions' => ['label' => 'Extra instructions', 'secret' => false, 'required' => false, 'help' => 'Shown on the invoice and order page.'],
        ];
    }

    public function details(): array
    {
        return collect($this->credentialFields())->keys()
            ->mapWithKeys(fn ($k) => [$k => $this->cred($k)])->filter()->all();
    }

    public function createPayment(Order $order, array $urls): PaymentAction
    {
        return PaymentAction::offline($this->details());
    }

    public function verifyReturn(Order $order, Request $request): PaymentResult
    {
        return PaymentResult::failed('Bank transfers are confirmed manually by an administrator.');
    }
}
