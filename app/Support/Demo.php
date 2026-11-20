<?php

namespace App\Support;

/**
 * Demo data for the MusicDesk customer portal and sales site.
 * MusicDesk is a white-label software provider. Customers buy LabelDesk
 * licenses and run the software on their own domains under their own brand.
 *
 * Demo-only content (tickets, team) until those modules are built.
 */
class Demo
{
    /** Seeded demo organisation (DatabaseSeeder, development only). */
    public const DEMO_ORG_EMAIL = 'billing@lowtide.test';

    public static function user(): array
    {
        return [
            'name' => 'Nova Reyes',
            'email' => 'nova@lowtide.co',
            'initials' => 'NR',
            'company' => 'Lowtide Distribution Ltd.',
            'company_initials' => 'LT',
        ];
    }

    /** LabelDesk license tiers (self-hosted, white-label) */
    public static function licenseTiers(): array
    {
        return [
            [
                'id' => 'starter', 'name' => 'Starter', 'price' => 499, 'lifetime' => 1490, 'domains' => 1, 'extra_domain' => 149,
                'blurb' => 'Launch one branded platform for your label or roster.',
                'features' => ['1 production domain + 1 staging', 'Full white-label: your logo, colors, domain', 'Releases, royalties & artist portal', '12 months of updates', 'Email support'],
            ],
            [
                'id' => 'business', 'name' => 'Business', 'price' => 1299, 'lifetime' => 3490, 'domains' => 3, 'extra_domain' => 129, 'featured' => true,
                'blurb' => 'For distributors running several brands or sub-labels.',
                'features' => ['3 production domains + staging', 'Everything in Starter', 'Multi-currency royalty accounting', 'DDEX delivery module', 'REST API & webhooks', 'Priority support'],
            ],
            [
                'id' => 'enterprise', 'name' => 'Enterprise', 'price' => 3499, 'lifetime' => null, 'domains' => 10, 'extra_domain' => 99,
                'blurb' => 'For large distributors and resellers with compliance needs.',
                'features' => ['10+ production domains', 'Everything in Business', 'Source-available license', 'SSO / SAML & audit log', 'Installation & migration service', 'Dedicated engineer & SLA'],
            ],
        ];
    }


    public static function releases(): array
    {
        return [
            [
                'version' => '4.3.0', 'date' => 'Nov 04, 2026', 'tag' => 'Latest',
                'notes' => [
                    ['new', 'Multi-currency royalty statements with locked FX rates'],
                    ['new', 'DDEX ERN 4.3 delivery profiles'],
                    ['improved', 'Theme editor: live preview for every brand color token'],
                    ['fixed', 'CSV import ignored trailing ISRC whitespace'],
                ],
            ],
            [
                'version' => '4.2.1', 'date' => 'Sep 18, 2026', 'tag' => null,
                'notes' => [
                    ['fixed', 'Statement PDFs cut off long track titles'],
                    ['security', 'Hardened session cookies on sub-domain installs'],
                ],
            ],
            [
                'version' => '4.2.0', 'date' => 'Aug 06, 2026', 'tag' => null,
                'notes' => [
                    ['new', 'Artist mobile web app (PWA) under your brand'],
                    ['new', 'Custom email templates per brand'],
                    ['improved', '3× faster catalog search'],
                ],
            ],
            [
                'version' => '4.1.0', 'date' => 'May 21, 2026', 'tag' => null,
                'notes' => [
                    ['new', 'Webhooks for releases, payouts and users'],
                    ['improved', 'Updater now verifies package signatures'],
                ],
            ],
        ];
    }

    public static function tickets(): array
    {
        return [
            ['id' => 'TCK-4821', 'subject' => 'Upgrade from 4.2.1 to 4.3.0 on sub-domain install', 'license' => 'LIC-24A7', 'status' => 'open', 'priority' => 'normal', 'updated' => '35 min ago', 'replies' => 3],
            ['id' => 'TCK-4790', 'subject' => 'Custom SMTP sender shows “via” in Gmail', 'license' => 'LIC-24A7', 'status' => 'awaiting', 'priority' => 'low', 'updated' => 'Yesterday', 'replies' => 5],
            ['id' => 'TCK-4655', 'subject' => 'Move license to a new server', 'license' => 'LIC-0B91', 'status' => 'resolved', 'priority' => 'normal', 'updated' => 'Nov 09', 'replies' => 4],
            ['id' => 'TCK-4410', 'subject' => 'White-label favicon not updating', 'license' => 'LIC-19C2', 'status' => 'resolved', 'priority' => 'low', 'updated' => 'Oct 21', 'replies' => 2],
        ];
    }

    public static function invoices(): array
    {
        return [
            ['id' => 'INV-20431', 'date' => 'Nov 14, 2026', 'item' => 'LabelDesk Business: extra domain (prorated)', 'amount' => 41.20, 'status' => 'paid'],
            ['id' => 'INV-19920', 'date' => 'Sep 22, 2026', 'item' => 'Installation & setup service', 'amount' => 349.00, 'status' => 'paid'],
            ['id' => 'INV-17003', 'date' => 'Mar 14, 2026', 'item' => 'LabelDesk Business: annual license', 'amount' => 1299.00, 'status' => 'paid'],
            ['id' => 'INV-16588', 'date' => 'Dec 01, 2025', 'item' => 'LabelDesk Starter: annual license', 'amount' => 499.00, 'status' => 'paid'],
            ['id' => 'INV-12004', 'date' => 'Aug 02, 2024', 'item' => 'LabelDesk Starter: lifetime license', 'amount' => 1490.00, 'status' => 'paid'],
        ];
    }

    public static function team(): array
    {
        return [
            ['name' => 'Nova Reyes', 'email' => 'nova@lowtide.co', 'role' => 'Owner', 'initials' => 'NR', 'last' => 'Active now'],
            ['name' => 'Jules Okafor', 'email' => 'jules@lowtide.co', 'role' => 'Billing', 'initials' => 'JO', 'last' => '2 days ago'],
            ['name' => 'Theo Park', 'email' => 'theo@lowtide.co', 'role' => 'Technical', 'initials' => 'TP', 'last' => '5 hours ago'],
        ];
    }

    public static function statusBadge(string $status): string
    {
        return match ($status) {
            'active', 'paid', 'resolved', 'online' => 'badge-mint',
            'expiring', 'pending', 'awaiting', 'outdated' => 'badge-amber',
            'open' => 'badge-volt',
            'expired', 'failed', 'suspended' => 'badge-coral',
            default => 'badge-ink',
        };
    }

    public static function money(float $v, int $dec = 2): string
    {
        return '$'.number_format($v, $dec);
    }
}
