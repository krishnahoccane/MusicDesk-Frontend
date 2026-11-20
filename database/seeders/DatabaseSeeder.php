<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Page;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\LocalBillingClient;
use App\Services\Orders\InvoiceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Local test accounts (development only; change or remove before production):
     *   Site admin:     admin@musicdesk.test  / musicdesk-admin-2026
     *   Demo customer:  demo@lowtide.test     / musicdesk-demo-2026
     */
    public function run(): void
    {
        $this->currencies();
        $this->catalog();
        $this->gateways();
        $this->pages();
        $this->users();
    }

    private function currencies(): void
    {
        // Rates per 1 USD. Updated daily by `php artisan currency:update-rates`.
        $rows = [
            ['USD', 'US Dollar', '$', 2, 1],
            ['INR', 'Indian Rupee', '₹', 2, 92.64],
            ['GBP', 'British Pound', '£', 2, 0.76],
            ['EUR', 'Euro', '€', 2, 0.91],
            ['CAD', 'Canadian Dollar', 'CA$', 2, 1.38],
            ['AUD', 'Australian Dollar', 'A$', 2, 1.53],
            ['JPY', 'Japanese Yen', '¥', 0, 154.2],
            ['AED', 'UAE Dirham', 'AED ', 2, 3.6725],
            ['SGD', 'Singapore Dollar', 'S$', 2, 1.34],
            ['BRL', 'Brazilian Real', 'R$', 2, 5.62],
            ['ZAR', 'South African Rand', 'R', 2, 18.1],
            ['NGN', 'Nigerian Naira', '₦', 2, 1545],
            ['MXN', 'Mexican Peso', 'MX$', 2, 18.4],
        ];
        foreach ($rows as [$code, $name, $symbol, $decimals, $rate]) {
            Currency::updateOrCreate(['code' => $code], [
                'name' => $name, 'symbol' => $symbol, 'decimals' => $decimals, 'rate' => $rate,
                'enabled' => true, 'auto_update' => $code !== 'USD', 'charm_pricing' => $code !== 'USD', 'rate_updated_at' => now(),
            ]);
        }
    }

    private function catalog(): void
    {
        $plans = [
            ['starter', 'Starter', 'Launch one branded platform for your label or roster.', 499, 1490, 1, 149, false,
                ['1 production domain + 1 staging', 'Full white-label: your logo, colors, domain', 'Releases, royalties & artist portal', '12 months of updates', 'Email support']],
            ['business', 'Business', 'For distributors running several brands or sub-labels.', 1299, 3490, 3, 129, true,
                ['3 production domains + staging', 'Everything in Starter', 'Multi-currency royalty accounting', 'DDEX delivery module', 'REST API & webhooks', 'Priority support']],
            ['enterprise', 'Enterprise', 'For large distributors and resellers with compliance needs.', 3499, null, 10, 99, false,
                ['10+ production domains', 'Everything in Business', 'Source-available license', 'SSO / SAML & audit log', 'Installation & migration service', 'Dedicated engineer & SLA']],
        ];
        foreach ($plans as $i => [$code, $name, $blurb, $annual, $lifetime, $domains, $extra, $featured, $features]) {
            Plan::updateOrCreate(['code' => $code], compact('name', 'blurb', 'domains', 'featured', 'features') + [
                'price_annual' => $annual, 'price_lifetime' => $lifetime, 'extra_domain_price' => $extra, 'active' => true, 'sort' => $i,
            ]);
        }

        $addons = [
            ['installation', 'Installation & setup', 'We install LabelDesk on your server and set up SSL, mail and cron jobs.', 'download', 349, null, false, true],
            ['branding', 'Branding package', 'Our designers apply your brand to the theme, emails and PDF statements.', 'sparkles', 590, null, false, true],
            ['priority_support', 'Priority support', 'Same-business-day responses and a private Slack channel.', 'headphones', 79, 'month', false, false],
            ['migration', 'Data migration', 'We import your catalog, artists, contracts and royalty history.', 'layers', 990, null, true, false],
        ];
        foreach ($addons as $i => [$code, $name, $description, $icon, $price, $interval, $from, $checkout]) {
            Addon::updateOrCreate(['code' => $code], compact('name', 'description', 'icon', 'price', 'interval', 'checkout') + ['price_from' => $from, 'active' => true, 'sort' => $i]);
        }
    }

    private function gateways(): void
    {
        $rows = [
            ['razorpay', 'Razorpay', false, null],
            ['stripe', 'Stripe', false, null],
            ['paypal', 'PayPal', false, null],
            // Enabled so the full checkout works locally without gateway keys. Replace with your real bank details.
            ['bank_transfer', 'Bank transfer', true, [
                'account_name' => 'MusicDesk Technologies', 'bank_name' => 'Example Bank',
                'account_number' => 'Set in /admin/gateways', 'routing' => 'Set in /admin/gateways',
                'instructions' => 'Use your order number as the payment reference.',
            ]],
        ];
        foreach ($rows as $i => [$code, $name, $enabled, $creds]) {
            $gw = PaymentGateway::firstOrNew(['code' => $code]);
            $gw->fill(['name' => $name, 'enabled' => $gw->exists ? $gw->enabled : $enabled, 'mode' => $gw->mode ?? 'test', 'sort' => $i]);
            if (! $gw->exists && $creds) {
                $gw->credentials = $creds;
            }
            $gw->save();
        }
    }

    private function pages(): void
    {
        $note = "> **Template.** Replace this text with your reviewed legal copy in /admin/pages.\n\n";
        $bodies = [
            'terms' => "# Terms & Conditions\n\n{$note}## 1. Who we are\nMusicDesk provides LabelDesk, white-label software licensed to businesses. We are a software vendor, not a music distributor.\n\n## 2. Licenses\nA license lets you install LabelDesk on the number of production domains in your plan. Licenses are per company and may not be resold except under a partner agreement.\n\n## 3. Payments\nPrices are listed in USD and converted to your currency at checkout. Invoices are issued by musicdesk.tech.\n\n## 4. Contact\nQuestions? Email us at the address on our contact page.",
            'privacy' => "# Privacy Policy\n\n{$note}We collect the account, billing and license information needed to provide the service. We use your IP address to show prices in your currency and times in your time zone. Payment details are processed by our payment providers and never stored on our servers.",
            'refund' => "# Refund Policy\n\n{$note}Every license comes with a 30-day money-back guarantee. Email support within 30 days of purchase for a full refund. Refunded licenses are deactivated.",
            'license-agreement' => "# LabelDesk License Agreement\n\n{$note}This agreement covers installation, permitted use, white-label rights, updates and support for LabelDesk.",
        ];
        foreach (config('site.pages') as $slug => $title) {
            Page::firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => $bodies[$slug] ?? "# $title", 'published' => true]);
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
