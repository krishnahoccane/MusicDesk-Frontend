<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Currency;
use App\Models\Page;
use App\Models\PaymentGateway;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Reference data every installation needs: currencies, plans, add-ons, payment gateways
 * (all switched off until configured in /admin/gateways) and legal page templates.
 * Idempotent: safe to run again; never overwrites gateway settings or edited pages.
 * Used by the web installer and by DatabaseSeeder (development).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->currencies();
        $this->catalog();
        $this->gateways();
        $this->pages();
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
            ['bank_transfer', 'Bank transfer', false, null],
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
}
