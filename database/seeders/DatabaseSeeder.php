<?php

namespace Database\Seeders;

use App\Licensing\LicenseService;
use App\Models\AdminRole;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseAddon;
use App\Models\LicenseAlert;
use App\Models\Organisation;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\Release;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Orders\InvoiceService;
use App\Support\Demo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
        $this->licenseAddons();
        $this->releases();
        $this->licenses();
        $this->support();
        $this->securityAlerts();
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
        ])->forceFill(['is_admin' => true, 'admin_role_id' => AdminRole::owner()->id, 'invite_accepted_at' => now()])->save();

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
            Release::updateOrCreate(['version' => $r['version'], 'addon_id' => null], [
                'channel' => 'stable',
                'released_at' => Carbon::parse($r['date'], 'UTC'),
                'notes' => array_map(fn ($n) => ['kind' => $n[0], 'text' => $n[1]], $r['notes']),
                'requirements' => $r['requirements'] ?? ['php' => 'PHP 8.2+ · Nginx or Apache', 'database' => 'MySQL 8 / PostgreSQL 15', 'extras' => 'Redis, cron, 2 GB RAM'],
                'requires_php' => $r['requires_php'] ?? null,
                'published' => true,
            ]);
        }
    }

    /** Licensed add-ons: demo services (DDEX, Content ID) and the LabelDesk plugins (bulk_upload, rest_api, incoming_webhooks, sso). */
    private function licenseAddons(): void
    {
        $plans = Plan::pluck('id', 'code');
        $rows = [
            ['ddex', 'DDEX delivery', 'Deliver releases to DSPs as DDEX ERN 4.3, with acknowledgement tracking.', 'layers', 590, 1490, ['business', 'enterprise']],
            ['sso', 'SSO / SAML', 'Single sign-on for staff and label users with Okta, Azure AD or Google Workspace.', 'shield', 390, null, ['enterprise']],
            ['content_id', 'YouTube Content ID', 'Claim and monetise user uploads, with matches in royalty statements.', 'play', 290, 790, []],
            // LabelDesk plugins (codes match "addon" in each plugin.json).
            ['bulk_upload', 'Bulk upload', 'Import artists and releases with tracks from Excel or CSV templates.', 'upload', 190, 490, ['business', 'enterprise']],
            ['rest_api', 'REST API', 'Read and write the catalogue from your own systems with API tokens.', 'link', 290, 790, ['enterprise']],
            ['incoming_webhooks', 'Incoming webhooks', 'Let other systems push releases, sales and events into LabelDesk.', 'zap', 190, 490, []],
        ];
        foreach ($rows as $i => [$code, $name, $description, $icon, $annual, $lifetime, $included]) {
            $addon = LicenseAddon::updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => $description, 'icon' => $icon, 'price_annual' => $annual, 'price_lifetime' => $lifetime, 'active' => true, 'sort' => $i,
            ]);
            $addon->plans()->sync(collect($included)->map(fn ($c) => $plans[$c] ?? null)->filter()->all());
        }

        $ddex = LicenseAddon::where('code', 'ddex')->first();
        Release::updateOrCreate(['version' => '1.2.0', 'addon_id' => $ddex->id], [
            'channel' => 'stable', 'released_at' => Carbon::parse('2026-10-28', 'UTC'), 'published' => true,
            'notes' => [['kind' => 'new', 'text' => 'ERN 4.3 takedown messages'], ['kind' => 'fixed', 'text' => 'Retry failed SFTP uploads']],
            'requirements' => ['php' => 'LabelDesk 4.2+'],
        ]);
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
        // A bought add-on with its own key, active on the main license's domain.
        [$contentId] = $service->createAddon($legacy, LicenseAddon::where('code', 'content_id')->firstOrFail(), false, ['billing_type' => 'lifetime', 'price_paid' => 790, 'currency' => 'USD'], 'seed');
        $this->activate($contentId, [['labels.sunsetwax.co', 'production', '1.0.3', 7]]);

        // Bought a year ago (minus 11 days), so it renews in 11 days.
        [$expiring] = $service->create($org, $starter, [
            'billing_type' => 'annual', 'price_paid' => 499, 'currency' => 'USD',
            'purchased_at' => now()->subYear()->addDays(11),
        ], 'seed');
        $this->activate($expiring, [['releases.driftrecords.net', 'production', '4.3.0', 12]]);
    }

    /** Demo tickets in each state, written directly (no emails). */
    private function support(): void
    {
        $org = Organisation::where('email', Demo::DEMO_ORG_EMAIL)->firstOrFail();
        if (SupportTicket::where('organisation_id', $org->id)->exists()) {
            return;
        }
        $customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
        $main = $org->licenses()->where('billing_type', 'annual')->whereHas('plan', fn ($q) => $q->where('code', 'business'))->first();
        $legacy = $org->licenses()->where('billing_type', 'lifetime')->first();

        $tickets = [
            [$main, 'music.lowtide.com', 'updating', 'normal', 'Upgrade from 4.2.1 to 4.3.0 on sub-domain install', 'open', 35, [
                [false, 'After running the updater on artists.lowtide.com we get a 500 on /admin/statements. Staging worked fine.', 190],
                [true, 'Thanks, Nova. 4.3.0 adds a new queue for FX rates. Could you run `php artisan queue:restart` and clear the config cache? That fixes it in every case we\'ve seen so far.', 120],
                [false, 'Restarted the queue and cleared the cache, still a 500. Log excerpt attached in the next message if needed.', 35],
            ]],
            [$main, 'portal.northbeat.fm', 'branding', 'low', 'Custom SMTP sender shows "via" in Gmail', 'awaiting', 60 * 26, [
                [false, 'Emails from portal.northbeat.fm show "via sendgrid.net" in Gmail. Can we fix that?', 60 * 50],
                [true, 'That label comes from SPF/DKIM alignment. Add the CNAME records SendGrid gives you for northbeat.fm, then send a test from Settings > Mail. Let us know once the DNS has propagated.', 60 * 26],
            ]],
            [$legacy, 'labels.sunsetwax.co', 'licensing', 'normal', 'Move license to a new server', 'resolved', 60 * 24 * 13, [
                [false, 'We are moving labels.sunsetwax.co to a new host next week. Do we need a new key?', 60 * 24 * 15],
                [true, 'No new key needed. Deactivate the old install from Licenses > Domains, then run the installer on the new server with the same key.', 60 * 24 * 14],
                [false, 'Done, all working. Thanks!', 60 * 24 * 13],
            ]],
        ];

        foreach ($tickets as $i => [$license, $domain, $category, $priority, $subject, $status, $minutes, $messages]) {
            $ticket = SupportTicket::create([
                'number' => 'TCK-'.(4801 + $i), 'organisation_id' => $org->id, 'user_id' => $customer->id, 'license_id' => $license?->id,
                'domain' => $domain, 'category' => $category, 'priority' => $priority, 'subject' => $subject, 'status' => $status,
                'assigned_to' => $admin->id, 'last_reply_at' => now()->subMinutes($minutes),
                'resolved_at' => $status === 'resolved' ? now()->subMinutes($minutes) : null,
                'created_at' => now()->subMinutes($messages[0][2]),
            ]);
            foreach ($messages as [$staff, $body, $ago]) {
                $ticket->messages()->create([
                    'user_id' => $staff ? $admin->id : $customer->id, 'author_name' => $staff ? 'Priya Shah' : $customer->name,
                    'from_staff' => $staff, 'body' => $body, 'created_at' => now()->subMinutes($ago),
                ]);
            }
        }
        $first = SupportTicket::where('number', 'TCK-4801')->first();
        $first?->messages()->create([
            'user_id' => $admin->id, 'author_name' => 'Priya Shah', 'from_staff' => true, 'internal' => true,
            'body' => 'Probably the statements cache table migration. Ask for storage/logs/laravel.log if the next reply has no log.', 'created_at' => now()->subMinutes(30),
        ]);
    }

    /** Demo security alerts: an unknown (likely nulled) install, a revoked-key reuse, and a blocked domain. */
    private function securityAlerts(): void
    {
        if (LicenseAlert::exists()) {
            return;
        }
        $legacy = License::where('billing_type', 'lifetime')->first();
        $rows = [
            ['crackedlabel-demo.example', 'open', 'no_license', ['no_license' => 14, 'signature_invalid' => 3], 17, 'install', null, '4.3.0', 'License file missing; core check bypass detected.', 40],
            ['records.unknown-label.example', 'open', 'invalid_key', ['invalid_key' => 4], 4, 'server', null, '4.2.1', 'Refused by the license server: License key not recognised.', 60 * 5],
            ['labels-mirror.sunsetwax.example', 'open', 'domain_mismatch', ['domain_mismatch' => 6], 6, 'install', $legacy, '4.1.0', 'Token issued for labels.sunsetwax.co', 60 * 20],
            ['nulled-scripts.example', 'blocked', 'signature_invalid', ['signature_invalid' => 52], 52, 'install', null, '4.2.0', 'License signature invalid.', 60 * 24 * 2],
        ];
        foreach ($rows as $i => [$domain, $status, $reason, $reasons, $attempts, $source, $license, $version, $message, $ago]) {
            LicenseAlert::create([
                'domain' => $domain, 'status' => $status, 'license_id' => $license?->id, 'last_reason' => $reason, 'reasons' => $reasons,
                'attempts' => $attempts, 'source' => $source, 'ip' => '198.51.100.'.(10 + $i), 'version' => $version, 'php_version' => '8.2.'.(5 + $i),
                'message' => $message, 'first_seen_at' => now()->subMinutes($ago + 60 * 24 * 3), 'last_seen_at' => now()->subMinutes($ago),
                'notified_at' => now()->subMinutes($ago), 'resolved_at' => $status === 'blocked' ? now()->subDays(2) : null,
                'resolved_by' => $status === 'blocked' ? 'Site Admin (admin)' : null, 'note' => $status === 'blocked' ? 'Nulled copy shared on a forum.' : null,
            ]);
        }
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
