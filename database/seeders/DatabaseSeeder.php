<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Billing\LocalBillingClient;
use App\Services\Orders\InvoiceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * DEVELOPMENT seed: reference data + demo accounts. Real installations use the web installer
 * (/install), which runs ReferenceDataSeeder only and creates your own super admin.
 *
 * Local test accounts (development only; change or remove before production):
 *   Site admin:     admin@musicdesk.test  / musicdesk-admin-2026
 *   Demo customer:  demo@lowtide.test     / musicdesk-demo-2026
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
        $this->demoBankTransfer();
        $this->users();
    }

    /** Enables bank transfer with placeholder details so checkout works locally without gateway keys. */
    private function demoBankTransfer(): void
    {
        $gw = PaymentGateway::findOrFail('bank_transfer');
        if (! $gw->credentials) {
            $gw->fill(['enabled' => true, 'credentials' => [
                'account_name' => 'MusicDesk Technologies', 'bank_name' => 'Example Bank',
                'account_number' => 'Set in /admin/gateways', 'routing' => 'Set in /admin/gateways',
                'instructions' => 'Use your order number as the payment reference.',
            ]])->save();
        }
    }

    private function users(): void
    {
        User::updateOrCreate(['email' => 'admin@musicdesk.test'], [
            'name' => 'Site Admin', 'password' => 'musicdesk-admin-2026', 'role' => 'owner',
        ])->forceFill(['is_admin' => true])->save();

        $org = Organisation::updateOrCreate(['billing_org_id' => LocalBillingClient::DEMO_ORG], [
            'name' => 'Lowtide Distribution Ltd.', 'email' => 'billing@lowtide.test', 'country' => 'GB',
            'vat_id' => 'GB284119307', 'currency' => 'USD', 'address' => ['text' => "14 Harbour Lane\nBristol BS1 4QA\nUnited Kingdom"],
            'billing_synced_at' => now(),
        ]);

        User::updateOrCreate(['email' => 'demo@lowtide.test'], [
            'name' => 'Nova Reyes', 'password' => 'musicdesk-demo-2026', 'role' => 'owner', 'organisation_id' => $org->id, 'country' => 'GB',
        ]);

        if ($org->invoices()->exists()) {
            return;
        }

        // Historical invoices for the demo organisation, numbered by year like real ones.
        $service = app(InvoiceService::class);
        $history = [
            ['2024-08-02', 'LabelDesk Starter: lifetime license', 1490],
            ['2025-12-01', 'LabelDesk Starter: annual license', 499],
            ['2026-03-14', 'LabelDesk Business: annual license', 1299],
            ['2026-09-22', 'Installation & setup', 349],
            ['2026-11-14', 'Extra production domain (prorated)', 40.29],
        ];
        foreach ($history as [$date, $desc, $amount]) {
            Carbon::setTestNow(Carbon::parse("$date 10:00:00", 'UTC'));
            Invoice::create([
                'number' => $service->nextNumber(),
                'organisation_id' => $org->id,
                'currency' => 'USD', 'exchange_rate' => 1,
                'lines' => [['description' => $desc, 'quantity' => 1, 'unit' => $amount, 'amount' => $amount]],
                'subtotal' => $amount, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => $amount,
                'status' => 'paid', 'seller' => $service->seller(), 'buyer' => $org->invoiceSnapshot(),
                'payment_method' => 'stripe', 'issued_at' => now(), 'paid_at' => now(),
            ]);
        }
        Carbon::setTestNow();
    }
}
