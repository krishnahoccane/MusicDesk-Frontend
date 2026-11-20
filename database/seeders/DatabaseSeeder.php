<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Licensing\LicenseService;
use App\Models\License;
use App\Models\Plan;
use App\Models\Release;
use App\Support\Demo;
use Illuminate\Support\Str;
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
        $this->releases();
        $this->licenses();
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

        $org = Organisation::updateOrCreate(['email' => Demo::DEMO_ORG_EMAIL], [
            'name' => 'Lowtide Distribution Ltd.', 'country' => 'GB',
            'vat_id' => 'GB284119307', 'currency' => 'USD', 'address' => ['text' => "14 Harbour Lane\nBristol BS1 4QA\nUnited Kingdom"],
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

    /** Release history (changelog only; upload packages in /admin/releases). */
    private function releases(): void
    {
        foreach (Demo::releases() as $r) {
            Release::updateOrCreate(['version' => $r['version']], [
                'channel' => 'stable',
                'released_at' => Carbon::parse($r['date'], 'UTC'),
                'notes' => array_map(fn ($n) => ['kind' => $n[0], 'text' => $n[1]], $r['notes']),
                'requirements' => ['php' => 'PHP 8.2+ · Nginx or Apache', 'database' => 'MySQL 8 / PostgreSQL 15', 'extras' => 'Redis, cron, 2 GB RAM'],
                'published' => true,
            ]);
        }
    }

    /** Three demo licenses covering the common states: busy annual, lifetime with lapsed updates, annual about to expire. */
    private function licenses(): void
    {
        $org = Organisation::where('email', Demo::DEMO_ORG_EMAIL)->firstOrFail();
        if ($org->licenses()->exists()) {
            return;
        }
        $service = app(LicenseService::class);
        $starter = Plan::where('code', 'starter')->firstOrFail();
        $business = Plan::where('code', 'business')->firstOrFail();

        [$main] = $service->create($org, $business, [
            'billing_type' => 'annual', 'domains_extra' => 1, 'price_paid' => 1299, 'currency' => 'USD',
            'purchased_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
        ], 'seed');
        $this->activate($main, [
            ['music.lowtide.com', 'production', '4.3.0', 2],
            ['portal.northbeat.fm', 'production', '4.3.0', 5],
            ['artists.lowtide.com', 'production', '4.2.1', 60 * 30],
            ['staging.lowtide.com', 'staging', '4.3.0', 90],
        ]);

        [$legacy] = $service->create($org, $starter, [
            'billing_type' => 'lifetime', 'price_paid' => 1490, 'currency' => 'USD',
            'purchased_at' => Carbon::parse('2024-08-02 10:00:00', 'UTC'),
        ], 'seed');
        $this->activate($legacy, [['labels.sunsetwax.co', 'production', '4.1.0', 7]]);

        // Bought a year ago (minus 11 days), so it renews in 11 days.
        [$expiring] = $service->create($org, $starter, [
            'billing_type' => 'annual', 'price_paid' => 499, 'currency' => 'USD',
            'purchased_at' => now()->subYear()->addDays(11),
        ], 'seed');
        $this->activate($expiring, [['releases.driftrecords.net', 'production', '4.3.0', 12]]);
    }

    /** @param  array<int, array{0: string, 1: string, 2: string, 3: int}>  $installs  domain, env, version, minutes since check-in */
    private function activate(License $license, array $installs): void
    {
        foreach ($installs as $i => [$domain, $env, $version, $minutes]) {
            $license->activations()->create([
                'domain' => $domain, 'environment' => $env, 'status' => 'active',
                'instance_id' => (string) Str::uuid(), 'ip' => '203.0.113.'.(20 + $i), 'version' => $version, 'php_version' => '8.3.'.(10 + $i),
                'last_check_in_at' => now()->subMinutes($minutes), 'activated_at' => $license->purchased_at->copy()->addDays(1 + $i),
            ]);
        }
    }
}
