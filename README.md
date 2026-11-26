# MusicDesk.tech

Storefront, customer portal, payments, invoicing, licensing and site admin for **MusicDesk**, a white-label software provider selling **LabelDesk** licenses.
Everything (orders, invoices, license keys, domains, suspensions, releases and the license server for LabelDesk installs) runs in this app. See [docs/licensing.md](docs/licensing.md).

Laravel 13 · Blade · Tailwind CSS v4 · Alpine.js (no React) · SQLite by default (any Laravel DB works)

## Install on a server (web installer)

1. Upload the code, then run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build` (or upload `vendor/` and `public/build/`).
2. Point the web server at `public/`. Make `storage/`, `bootstrap/cache/` and the application folder (for `.env`) writable.
3. Open the site. Every page goes to **`/install`** until installation is finished:
   1. **Prerequisites:** PHP 8.3+, required extensions, a PDO driver, writable folders, built assets.
   2. **Database:** MySQL, MariaDB, PostgreSQL or SQLite. Tests the connection, then runs the migrations and loads reference data (currencies, plans, add-ons, legal page templates; payment gateways start switched off). No demo accounts.
   3. **Redis (optional):** tests the connection and chooses what uses it (cache, sessions, queue), or skip to keep them in the database. Works with the phpredis extension or the bundled predis client.
   4. **Email (SMTP):** signs in to your SMTP server to check the credentials, and can send a test email.
   5. **Super admin:** site name, site URL and your admin account. Production mode is on by default (`APP_ENV=production`, `APP_DEBUG=false`).
4. After step 5 the installer is gone (`/install` returns 404) and you sign in at `/login`. Then add payment gateway keys in `/admin/gateways` and add a cron entry: `* * * * * php /path/to/artisan schedule:run`.

Each step writes `.env` as it goes, and the `APP_KEY` is generated automatically if missing. Installation is recorded in `storage/app/installed.json`. To run the installer again, delete that file. `APP_INSTALLED=true|false` in `.env` overrides the file.

## Run locally (development)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate --seed       # reference data + demo accounts
php artisan storage:link
npm run build            # or: npm run dev
php -r "file_put_contents('storage/app/installed.json', '{}');"   # skip the web installer locally
php artisan serve
php artisan schedule:work   # optional: daily exchange rates and license renewal reminders
```

### Local test accounts (created by the seeder, development only)

| Role | Email | Password |
|---|---|---|
| Site admin (`/admin`) | `admin@musicdesk.test` | `musicdesk-admin-2026` |
| Demo customer (`/dashboard`) | `demo@lowtide.test` | `musicdesk-demo-2026` |

Change or remove these before deploying (`database/seeders/DatabaseSeeder.php`).

## What’s where

| Area | URL | Notes |
|---|---|---|
| Sales site | `/`, `/pricing`, `/contact`, `/legal/{slug}` | Text, colors, fonts, plans, contact and legal pages are all editable in `/admin` |
| Checkout | `/checkout/{plan}` → `/orders/{number}` | Creates the organisation, takes payment, issues the invoice and the license key |
| License purchases | `/dashboard/licenses/{id}/buy/{kind}` | Renewal, updates renewal, extra domains, plan upgrade, switch to lifetime (prorated) |
| Customer portal | `/dashboard/*` | Licenses (reveal key, domains, check-in, certificate, offline activation), downloads, billing & invoices, support tickets, settings |
| Site admin | `/admin/*` | Team & roles, licenses, license status & security alerts, support tickets, customers, releases, orders & invoices, branding, homepage text, pricing plans/add-ons/coupons, legal pages, contact & social, payment gateways, currencies, invoicing, localisation |
| License server | `/api/license/v1/*` | Activate, check-in, deactivate, public key for LabelDesk installs (Ed25519-signed tokens), and `/report`, the security webhook installs call when their license check fails |
| Webhooks | `/webhooks/payments/{gateway}` | Signature-verified, idempotent |

## Key features

- **Payment gateways:** Razorpay, Stripe, PayPal and bank transfer, configured in `/admin/gateways` (encrypted keys, test/live, per-currency). Add more by implementing `App\Payments\PaymentGateway` and registering in `GatewayManager::DRIVERS`.
- **Invoices generated here:** `PREFIX-YEAR-000001` numbering, tax rules per country, reverse charge for foreign B2B, PDF via dompdf.
- **Multi-currency:** USD default. Rates update daily (`php artisan currency:update-rates`, scheduled 00:05 UTC) from open.er-api.com or Frankfurter. Converted prices are rounded down to end in 9s within a 3% discount: $499 → ₹45,999 · £379 · €449.
- **Time zones:** the app and database run in UTC. Each visitor’s time zone comes from their IP (ipapi.co / ipwho.is), falling back to their browser, and can be set in their profile. All dates are shown in local time.
- **Licensing:** keys hashed + encrypted, reveals audited, domain limits with free staging, suspend / reinstate / revoke, full history, release downloads limited to the updates period, renewal reminders 30/14/3 days before expiry (`licenses:notify-expiring`, 08:00 UTC). Keep `APP_KEY` safe: it also protects the license signing key.
- **Licensed add-ons:** optional modules set up under Pricing plans with annual / lifetime prices and the plans that include them. Each add-on a customer has is a separate license with its own `LDAD-` key, running only on its main license's domains. Customers buy them from the portal; plans that include one issue the key automatically (also on upgrade). Releases can belong to an add-on. See [docs/licensing.md](docs/licensing.md#add-ons).
- **Free trials:** plans can offer an N-day trial with limits; it ends like an expired license and is then suspended. See [docs/licensing.md](docs/licensing.md) and [docs/labeldesk-changes-2.0.1.md](docs/labeldesk-changes-2.0.1.md). Changes per version: [CHANGELOG.md](CHANGELOG.md).
- **Over-the-air updates (LabelDesk 4.3.3+):** `POST /api/license/v1/updates/check` and `/updates/download` serve signed LabelDesk and add-on packages within each key's updates period. LabelDesk ZIPs are signed on upload when they aren't yet. See [docs/licensing.md](docs/licensing.md).
- **Deactivation codes (LabelDesk 4.3.2+):** deactivating an installation from LabelDesk needs a one-time code emailed to the license owner (`POST /api/license/v1/deactivate/code`); domain transfers and key changes by the same installation don't. See [docs/licensing.md](docs/licensing.md).
- **Security alerts:** failed license checks (reported by LabelDesk or refused by the license server) are emailed to the site admins and listed under Licenses › Status, where a domain can be issued a license or blocked. See [docs/licensing.md](docs/licensing.md).
- **Admin team & roles:** `/admin/team`. Invite team members by email (signed link, 7 days, works once; the link is also shown so you can send it yourself), change their role or remove them. Roles grant admin areas: licenses, security status, customers, releases, support, orders & invoices, storefront, payments & settings, team. Defaults: Owner (everything, locked), Administrator, License manager, Support agent, Billing; Owners can add or edit roles. Menus, dashboard cards and routes follow the role, security alerts go to members with the security area and ticket emails to members with support. Guard rails: nobody changes their own access, only Owners manage Owners or hand out roles beyond their own, and the last Owner can't be removed. Customer accounts can't be invited; use a separate work email.
- **Support tickets:** customers open tickets from the portal (license, domain, category, priority, attachments); admins answer from `/admin/support` with replies or internal notes. Both sides are emailed.

## Tests

```bash
php artisan test
```
Covers charm pricing, gateway signatures, checkout → invoice → license, the license server API, security reports and domain blocking, support tickets, admin team roles, licensed add-ons, license purchases and refunds, domain limits, reminders, webhooks, admin access, theming and time zones. External HTTP is faked.
