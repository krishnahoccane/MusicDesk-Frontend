<?php

/*
|--------------------------------------------------------------------------
| Site settings schema & defaults
|--------------------------------------------------------------------------
| Everything here is editable from /admin. The admin forms are generated
| from this schema, and setting('group.key') falls back to these defaults.
| Field types: text, textarea, color, font, image, url, email, select, toggle, number
*/

$fonts = ['Inter Tight', 'Inter', 'Manrope', 'DM Sans', 'Plus Jakarta Sans', 'Space Grotesk', 'Outfit', 'Sora', 'Poppins', 'Figtree', 'Instrument Serif', 'Playfair Display', 'Fraunces', 'DM Serif Display', 'JetBrains Mono', 'IBM Plex Mono', 'Space Mono'];

return [

    'fonts' => $fonts,

    'groups' => [

        'branding' => [
            'label' => 'Branding', 'icon' => 'sparkles',
            'description' => 'Logo, colors and fonts used across the site and the customer portal.',
            'fields' => [
                'site_name' => ['type' => 'text', 'label' => 'Site name', 'default' => 'MusicDesk'],
                'site_suffix' => ['type' => 'text', 'label' => 'Logo suffix', 'default' => '.tech', 'help' => 'Shown dimmed after the name in the logo.'],
                'product_name' => ['type' => 'text', 'label' => 'Product name', 'default' => 'LabelDesk'],
                'logo' => ['type' => 'image', 'label' => 'Logo image', 'default' => null, 'help' => 'Optional. Replaces the built-in logo mark. SVG or PNG, square.'],
                'favicon' => ['type' => 'image', 'label' => 'Favicon', 'default' => null],
                'og_image' => ['type' => 'image', 'label' => 'Social share image', 'default' => null, 'help' => '1200×630 recommended.'],
                'color_primary' => ['type' => 'color', 'label' => 'Primary color', 'default' => '#7c5cff'],
                'color_secondary' => ['type' => 'color', 'label' => 'Secondary color', 'default' => '#ff3fd2'],
                'color_accent' => ['type' => 'color', 'label' => 'Accent color', 'default' => '#ffb547'],
                'color_success' => ['type' => 'color', 'label' => 'Success color', 'default' => '#22d9a0'],
                'color_background' => ['type' => 'color', 'label' => 'Background color', 'default' => '#07070b'],
                'font_sans' => ['type' => 'font', 'label' => 'Body & heading font', 'default' => 'Inter Tight'],
                'font_serif' => ['type' => 'font', 'label' => 'Accent (italic) font', 'default' => 'Instrument Serif'],
                'font_mono' => ['type' => 'font', 'label' => 'Monospace font', 'default' => 'JetBrains Mono'],
            ],
        ],

        'home' => [
            'label' => 'Homepage text', 'icon' => 'file',
            'description' => 'Headlines and copy on the homepage.',
            'fields' => [
                'announcement' => ['type' => 'text', 'label' => 'Announcement pill', 'default' => 'LabelDesk 4.3: multi-currency royalty statements'],
                'hero_line1' => ['type' => 'text', 'label' => 'Headline, line 1', 'default' => 'Your music platform.'],
                'hero_accent' => ['type' => 'text', 'label' => 'Headline, italic accent', 'default' => 'Your brand.'],
                'hero_line2' => ['type' => 'text', 'label' => 'Headline, line 2 end', 'default' => 'Our software.'],
                'hero_subtitle' => ['type' => 'textarea', 'label' => 'Hero subtitle', 'default' => 'LabelDesk is white-label software for record labels and distributors. Run releases, royalty accounting and an artist portal on your own domain, with your logo. We build the software. You own the business.'],
                'cta_primary' => ['type' => 'text', 'label' => 'Primary button', 'default' => 'Buy a license'],
                'cta_secondary' => ['type' => 'text', 'label' => 'Secondary button', 'default' => 'See it with your brand'],
                'product_heading' => ['type' => 'text', 'label' => 'Product section heading', 'default' => 'A complete platform,'],
                'product_accent' => ['type' => 'text', 'label' => 'Product section accent', 'default' => 'ready to rebrand.'],
                'whitelabel_heading' => ['type' => 'text', 'label' => 'White-label section heading', 'default' => 'Every pixel says'],
                'whitelabel_accent' => ['type' => 'text', 'label' => 'White-label section accent', 'default' => 'your name.'],
                'pricing_heading' => ['type' => 'text', 'label' => 'Pricing section heading', 'default' => 'Pay for software.'],
                'pricing_accent' => ['type' => 'text', 'label' => 'Pricing section accent', 'default' => 'Keep every cent you earn.'],
                'cta_heading' => ['type' => 'text', 'label' => 'Final CTA heading', 'default' => 'Launch your platform'],
                'cta_accent' => ['type' => 'text', 'label' => 'Final CTA accent', 'default' => 'under your name.'],
                'cta_text' => ['type' => 'text', 'label' => 'Final CTA text', 'default' => '30-day money-back guarantee on every license.'],
                'footer_blurb' => ['type' => 'textarea', 'label' => 'Footer description', 'default' => 'White-label software for music businesses. We build the platform, and you run it under your own name.'],
            ],
        ],

        'contact' => [
            'label' => 'Contact & social', 'icon' => 'mail',
            'description' => 'Contact details and social links shown in the footer and on the contact page.',
            'fields' => [
                'email_sales' => ['type' => 'email', 'label' => 'Sales email', 'default' => 'sales@musicdesk.tech'],
                'email_support' => ['type' => 'email', 'label' => 'Support email', 'default' => 'support@musicdesk.tech'],
                'phone' => ['type' => 'text', 'label' => 'Phone', 'default' => '+1 (555) 010-4242'],
                'whatsapp' => ['type' => 'text', 'label' => 'WhatsApp number', 'default' => null],
                'address' => ['type' => 'textarea', 'label' => 'Address', 'default' => "MusicDesk\n548 Market St\nSan Francisco, CA 94104"],
                'hours' => ['type' => 'text', 'label' => 'Support hours', 'default' => 'Mon–Fri, 9:00–18:00 UTC'],
                'social_x' => ['type' => 'url', 'label' => 'X (Twitter)', 'default' => null],
                'social_instagram' => ['type' => 'url', 'label' => 'Instagram', 'default' => null],
                'social_linkedin' => ['type' => 'url', 'label' => 'LinkedIn', 'default' => null],
                'social_youtube' => ['type' => 'url', 'label' => 'YouTube', 'default' => null],
                'social_facebook' => ['type' => 'url', 'label' => 'Facebook', 'default' => null],
                'social_github' => ['type' => 'url', 'label' => 'GitHub', 'default' => null],
                'social_discord' => ['type' => 'url', 'label' => 'Discord', 'default' => null],
            ],
        ],

        'invoice' => [
            'label' => 'Invoicing', 'icon' => 'receipt',
            'description' => 'Seller details, numbering and tax printed on invoices generated by this site.',
            'fields' => [
                'company_name' => ['type' => 'text', 'label' => 'Legal company name', 'default' => 'MusicDesk Technologies'],
                'company_address' => ['type' => 'textarea', 'label' => 'Company address', 'default' => "548 Market St\nSan Francisco, CA 94104\nUnited States"],
                'company_tax_id' => ['type' => 'text', 'label' => 'Tax / VAT / GST number', 'default' => null],
                'seller_country' => ['type' => 'text', 'label' => 'Company country (ISO code)', 'default' => 'US', 'help' => 'Two-letter code, e.g. US, IN, GB. Used for tax rules.'],
                'company_email' => ['type' => 'email', 'label' => 'Billing email', 'default' => 'billing@musicdesk.tech'],
                'prefix' => ['type' => 'text', 'label' => 'Invoice number prefix', 'default' => 'MD', 'help' => 'Invoices are numbered PREFIX-YEAR-000001.'],
                'tax_rate' => ['type' => 'number', 'label' => 'Default tax rate (%)', 'default' => 0, 'help' => 'Applied when no country rule matches.'],
                'tax_rules' => ['type' => 'textarea', 'label' => 'Tax by country', 'default' => "IN=18\nGB=20\nDE=19\nFR=20\nIT=22\nNL=21", 'help' => 'One rule per line: COUNTRY=PERCENT. Customers with a valid VAT/GST ID outside your country are not charged tax.'],
                'due_days' => ['type' => 'number', 'label' => 'Bank transfer due (days)', 'default' => 14],
                'footer' => ['type' => 'textarea', 'label' => 'Invoice footer note', 'default' => 'Thank you for your business. Questions about this invoice? Email billing@musicdesk.tech.'],
            ],
        ],

        'pricing' => [
            'label' => 'Pricing', 'icon' => 'tag', 'hidden' => true, // edited on /admin/plans
            'fields' => [
                'coupons' => ['type' => 'textarea', 'label' => 'Coupons', 'default' => "LAUNCH10=10", 'help' => 'One per line: CODE=PERCENT. Discounts apply to license and domain lines, not services.'],
                'lifetime_note' => ['type' => 'text', 'label' => 'Lifetime license note', 'default' => 'Pay once, use it forever. Includes 12 months of updates and support. Renew updates any time at 40% off.'],
                'annual_note' => ['type' => 'text', 'label' => 'Annual license note', 'default' => 'Updates and support included for as long as you renew. If you stop renewing, LabelDesk keeps running on the last version you installed.'],
            ],
        ],

        'general' => [
            'label' => 'Localisation', 'icon' => 'globe',
            'description' => 'Currency and time zone behaviour. All dates are stored in UTC.',
            'fields' => [
                'default_currency' => ['type' => 'text', 'label' => 'Default currency', 'default' => 'USD'],
                'currency_by_country' => ['type' => 'toggle', 'label' => 'Pick currency from visitor’s country', 'default' => true],
                'timezone_by_ip' => ['type' => 'toggle', 'label' => 'Detect time zone from visitor’s IP', 'default' => true],
                'geoip_provider' => ['type' => 'select', 'label' => 'IP location provider', 'default' => 'ipapi', 'options' => ['ipapi' => 'ipapi.co (HTTPS, free tier)', 'ipwhois' => 'ipwho.is (HTTPS, free)', 'none' => 'Off (browser time zone only)']],
                'rates_provider' => ['type' => 'select', 'label' => 'Exchange-rate provider', 'default' => 'open_er_api', 'options' => ['open_er_api' => 'open.er-api.com (free, daily)', 'frankfurter' => 'Frankfurter / ECB (free, daily)']],
                'charm_max_discount' => ['type' => 'number', 'label' => 'Charm pricing: max discount (%)', 'default' => 3, 'help' => 'Converted prices are rounded down to end in 9s, using as many 9s as fit within this discount. 3% → ₹46,227 becomes ₹45,999, €454 becomes €449.'],
                'show_converted_original' => ['type' => 'toggle', 'label' => 'Show exact conversion struck through', 'default' => true],
            ],
        ],
    ],

    /* Countries → currency (used when currency_by_country is on) */
    'country_currency' => [
        'IN' => 'INR', 'GB' => 'GBP', 'US' => 'USD', 'CA' => 'CAD', 'AU' => 'AUD', 'JP' => 'JPY', 'BR' => 'BRL', 'AE' => 'AED',
        'SG' => 'SGD', 'NZ' => 'NZD', 'ZA' => 'ZAR', 'NG' => 'NGN', 'MX' => 'MXN',
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'IE' => 'EUR',
        'PT' => 'EUR', 'FI' => 'EUR', 'GR' => 'EUR', 'LU' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR', 'EE' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR', 'HR' => 'EUR',
    ],

    /* Legal pages editable in /admin → Pages */
    'pages' => [
        'terms' => 'Terms & Conditions',
        'privacy' => 'Privacy Policy',
        'refund' => 'Refund Policy',
        'license-agreement' => 'License Agreement',
    ],
];
