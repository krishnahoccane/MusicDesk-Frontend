# MusicDesk.tech

Storefront, customer portal, payments, invoicing and site admin for **MusicDesk**, a white-label software provider selling **LabelDesk** licenses.
Client, subscription and license administration lives in **billing.twsd.me** (see [docs/billing-integration.md](docs/billing-integration.md)).

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
php artisan schedule:work   # optional: runs the daily exchange-rate update
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
| Checkout | `/checkout/{plan}` → `/orders/{number}` | Creates the organisation (also in billing), takes payment, issues the invoice, gets the license key from billing |
| Customer portal | `/dashboard/*` | Licenses (from billing), downloads, billing & invoices (generated here), support, settings |
| Site admin | `/admin/*` | Branding, homepage text, pricing plans/add-ons/coupons, legal pages, contact & social, payment gateways, currencies, invoicing, localisation, orders & invoices |
| Webhooks | `/webhooks/payments/{gateway}`, `/webhooks/wcc` | Signature-verified, idempotent |

## Key features

- **Payment gateways:** Razorpay, Stripe, PayPal and bank transfer, configured in `/admin/gateways` (encrypted keys, test/live, per-currency). Add more by implementing `App\Payments\PaymentGateway` and registering in `GatewayManager::DRIVERS`.
- **Invoices generated here:** `PREFIX-YEAR-000001` numbering, tax rules per country, reverse charge for foreign B2B, PDF via dompdf. A signed PDF link is sent to billing.
- **Multi-currency:** USD default. Rates update daily (`php artisan currency:update-rates`, scheduled 00:05 UTC) from open.er-api.com or Frankfurter. Converted prices are rounded down to end in 9s within a 3% discount: $499 → ₹45,999 · £379 · €449.
- **Time zones:** the app and database run in UTC. Each visitor’s time zone comes from their IP (ipapi.co / ipwho.is), falling back to their browser, and can be set in their profile. All dates are shown in local time.
- **billing.twsd.me client:** `WCC_BILLING_ENABLED=false` uses a local stand-in (keys generated here, calls logged) so everything works before billing is live.

## Tests

```bash
php artisan test
```
Covers charm pricing, gateway and billing signatures, checkout → invoice → license, webhooks, admin access, theming and time zones. External HTTP is faked.
