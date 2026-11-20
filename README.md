# MusicDesk.tech

Storefront, customer portal, payments, invoicing and site admin for **MusicDesk**, a white-label software provider selling **LabelDesk** licenses.
Client, subscription and license administration lives in **billing.twsd.me** (see [docs/billing-integration.md](docs/billing-integration.md)).

Laravel 13 · Blade · Tailwind CSS v4 · Alpine.js (no React) · SQLite by default (any Laravel DB works)

## Run locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate --seed
php artisan storage:link
npm run build            # or: npm run dev
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
