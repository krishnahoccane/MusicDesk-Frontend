# musicdesk.tech ⇄ billing.twsd.me: Integration

**Version:** 3.1 · **Date:** 2026-11-20
**Source of truth for the API:** TWSD Billing `docs/LICENSING.md` (phase 1, "licensing core") and `app/Modules/Licensing/Support/ApiPresenter.php`.
This document describes how **musicdesk.tech uses that API**, what is wired, and what billing still needs to add.

---

## 1. Who owns what

| Concern | Owner |
|---|---|
| Customers (organisations), licenses, activations/domains, releases, license server, key generation, suspension | **billing.twsd.me** (system of record) |
| Sign-up/login, checkout, payments (Razorpay, Stripe, PayPal, bank), **invoices**, refunds, tax, currency conversion, pricing shown to customers | **musicdesk.tech** |
| Admin for clients, subscriptions and licenses | **billing.twsd.me** → *Software Licensing* |
| Admin for the storefront (branding, text, plans, gateways, currencies, legal pages) | **musicdesk.tech** → `/admin` |

musicdesk.tech takes the payment, issues its own invoice, then **reports the paid order** with `POST /orders`. Billing creates the customer (on the first order) and the license, and generates the key. LabelDesk sales never go to Zoho Books.

---

## 2. Setup

1. In billing: *Licensing → Storefronts → Add a storefront*
   - Code: `musicdesk`
   - Webhook URL: `https://musicdesk.tech/webhooks/wcc`
   - Copy the API key and webhook secret. They're shown once.
2. In billing: *Products & Plans*
   - Product `labeldesk` with key prefix `LDSK`.
   - **Plan codes must match musicdesk.tech's plans:** `starter`, `business`, `enterprise` (see `musicdesk.tech/admin/plans`).
   - Billing's price list is for reference only: billing doesn't check amounts. musicdesk.tech's own plans, currency conversion and rounding to …9 set what customers pay.
3. In musicdesk.tech `.env`:
   ```dotenv
   WCC_BILLING_ENABLED=true
   WCC_BILLING_URL=https://billing.twsd.me/api/v1/storefront
   WCC_BILLING_KEY=sk_live_…
   WCC_BILLING_STOREFRONT=musicdesk
   WCC_BILLING_WEBHOOK_SECRET=whsec_…
   ```
   Then run `php artisan config:clear`.
4. Existing Workspace clients who buy LabelDesk: in billing, *Link an existing organisation as customer*, then set that `cus_…` id on the organisation in musicdesk.tech (`organisations.billing_org_id`). This avoids creating a duplicate customer.

With `WCC_BILLING_ENABLED=false`, musicdesk.tech uses a **local stand-in** (`LocalBillingClient`). It behaves the same way (customer created on the first order, keys revealed on request, domain rules), so everything works before billing is connected.

---

## 3. Conventions (as built in billing)

- `Authorization: Bearer sk_live_…` on every call.
- `X-Customer-Id: cus_…` on customer-scoped calls. musicdesk.tech stores it as `organisations.billing_org_id`.
- `X-Acting-User`: musicdesk.tech sends the signed-in user's email (allowed characters `A-Za-z0-9_-@.`), which billing records in the license history and audit log.
- `Idempotency-Key` on `POST /orders` and the activation POSTs, kept for 24 h. Same key + same body replays the original response; same key + a different body → `422 idempotency_key_reused`.
  - musicdesk.tech uses `order-{order number}`, so retries and "Send to billing again" can never create a second license.
- Money is in **minor units**: `{ "amount": 4599900, "currency": "INR" }` (paise, cents).
- Dates are ISO 8601 UTC.
- Errors: `{ "error": { "code", "message", "field" }, "request_id" }`.
- musicdesk.tech retries network errors and 5xx twice. 4xx are final. Rate limit: 600/min per key.

---

## 4. What musicdesk.tech calls, and when

| When | Call | musicdesk.tech code |
|---|---|---|
| Customer registers (country required) | `POST /customers` `{ company, email, name, country, vat_id, currency }`, `Idempotency-Key: customer-{organisation id}` → stores `cus_…`. If billing is down, the first `POST /orders` creates the customer instead | `OrganisationService::syncToBilling` → `HttpBillingClient::createOrganisation` |
| Payment succeeds (Razorpay / Stripe / PayPal, by return or webhook) | `POST /orders` `kind: new_license`, `payment_status: paid` | `OrderFulfiller::markPaid` → `submitOrder` |
| Bank transfer order placed | `POST /orders` with `payment_status: pending` → license `pending`, usable 14 days | `OrderFulfiller::awaitOffline` |
| Admin marks the bank transfer paid | `POST /orders/{ord}/confirm-payment` `{ payment_reference, paid_at }` → order `paid`, license `pending` → `active` (idempotent) | `OrderFulfiller::confirmInBilling` |
| Refund recorded in `/admin/orders` | `POST /orders/{ord}/refund` `{ refund_reference, reason }` → order `refunded`; a new-license order’s license is **revoked** (idempotent) | `OrderFulfiller::refundInBilling` |
| Customer edits billing details | `PATCH /customers/{cus}` (company, billing_email, country, currency, address.line1) | `HttpBillingClient::updateOrganisation` |
| Portal: Licenses / Overview / Downloads / Billing pages | `GET /licenses?include=activations&limit=100` (follows `next_cursor`), cached 60 s | `HttpBillingClient::licenses` |
| Portal: 👁 reveal / copy key, and the order-complete page | `POST /licenses/{lic}/reveal-key`: audited by billing, never cached by musicdesk.tech | `LicenseActionController::reveal` |
| Portal: Add domain | `POST /licenses/{lic}/activations` `{ domain, environment }` | `…::addDomain` |
| Portal: Move to another domain | `POST /licenses/{lic}/activations/{act}/move` `{ new_domain }` (max 3 per 30 days) | `…::moveDomain` |
| Portal: Deactivate domain | `DELETE /licenses/{lic}/activations/{act}` | `…::deactivateDomain` |
| Portal: Force check-in | `POST /licenses/{lic}/activations/{act}/check-in-request` | `…::checkIn` |
| Portal: Auto-renew on/off | `PATCH /licenses/{lic}` `{ auto_renew }` | `…::autoRenew` |

Portal license actions check that the license belongs to the signed-in organisation before calling billing (billing also enforces this with `X-Customer-Id`). Roles: owner, admin and technical can change domains; owner, admin and billing can change auto-renew. Billing errors (`domain_limit_reached`, `domain_in_use`, `license_suspended`, …) are shown to the customer in plain language.

### 4.1 `POST /orders` as musicdesk.tech sends it

First order when billing was unreachable at sign-up, so the customer doesn’t exist there yet (bank transfer, INR). Normally the customer already exists from `POST /customers`, and the order sends `customer: { "id": "cus_…" }` instead:
```json
{
  "kind": "new_license",
  "customer": { "id": null, "name": "Ravi Kumar", "email": "ravi@bassline.in", "company": "Bassline Music Pvt Ltd", "country": "IN", "vat_id": null },
  "product": "labeldesk", "plan_code": "starter", "billing_type": "annual",
  "extra_domains": 1, "primary_domain": "portal.bassline.in", "auto_renew": true,
  "amount": { "amount": 10832046, "currency": "INR" },
  "payment_provider": "bank_transfer", "payment_reference": "ORD-261120-K3QZP",
  "payment_status": "pending", "invoice_number": "MD-2026-000006"
}
```
- **Orders for an existing customer** send `customer: { "id": "cus_…" }` and the `X-Customer-Id` header.
- **`amount`** is the invoice total including tax, in the currency the customer paid. musicdesk.tech's invoice keeps the exchange rate, so the USD value is always on record (see §7.5).
- **`payment_reference`** is the gateway's payment id (e.g. `pay_…` for Razorpay), or the order number for bank transfers.

From billing's `201` response, musicdesk.tech stores:
- `customer_id` → `organisations.billing_org_id` (only if it wasn’t set at sign-up)
- `id` (`ord_…`) → `orders.meta.billing_order_id`, used later for `confirm-payment` and `refund`
- `license.id` → `orders.billing_license_id`
- `license.number` → `orders.meta.license_number`

The full key is **not** in the response. The customer reveals it on the order-complete page or in the portal.

If billing is unreachable, checkout still completes and the invoice is issued. The order shows *"not in billing"* in `musicdesk.tech/admin/orders`, with a **Retry in billing** action (same idempotency key).

### 4.2 License fields used by the portal

From `ApiPresenter::license`:

| Billing field | Portal use |
|---|---|
| `number` | License label (LIC-24A7) |
| `id` | Action URLs |
| `plan_name`, `plan_code` | Tier |
| `billing_type` | Annual / Lifetime |
| `status` | Status badge (expiring is worked out when ≤ 30 days remain) |
| `status_reason` | Shown in the suspended/revoked banner |
| `key_masked` | Shown masked |
| `domains_included` + `domains_extra` | Domain allowance |
| `domains_used` | Usage bar |
| `expires_at` | Renewal date |
| `support_until` | "Updates expired" state |
| `pending_until` | Bank-transfer grace banner |
| `price_paid` (minor units) | "Paid" amount, in the order's currency |
| `auto_renew` | Toggle state |
| `activations[]`: domain, environment, status, server_ip, version, php_version, last_check_in_at, online | Domains & installations table |

All dates are shown in the viewer's time zone.

---

## 5. Webhooks from billing

`POST https://musicdesk.tech/webhooks/wcc` (`WebhookController::wcc`)

- **Signature:** `WCC-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`. The request is rejected if the signature doesn't match or the timestamp is more than 5 minutes off.
- **Body:** the signed raw JSON `{ id, type, created_at, storefront, customer_id, data }`.
- **Dedupe:** on `id` (`webhook_events` table). Events whose `storefront` isn't `musicdesk` are acknowledged and ignored.
- **Effect:** every event type billing sends (`license.created`, `license.updated`, `license.suspended`, `license.revoked`, `license.expiring`, `license.expired`, `activation.created`, `activation.deactivated`) clears the cached licenses for that `customer_id`. The portal then shows billing's current state, such as a suspension banner with `status_reason`, on the next page view.
- **Emails:** expiry and renewal reminder emails aren't sent by musicdesk.tech yet. See §7.4.

---

## 6. Suspension on customers' sites

Billing's admin suspends, reinstates or revokes a license. The LabelDesk install learns about it from the license server at `/api/license/v1`:
- the next check-in (every 24 h) refuses suspended and revoked licenses
- tokens are Ed25519 JWTs, valid 30 days

The customer portal shows the status and reason after the webhook. An expired annual license keeps working without updates.

LabelDesk itself needs the license-server URL and the Ed25519 public key from billing's Storefronts page.

---

## 7. Payment lifecycle (added to billing on 2026-11-20)

The three gaps found during integration now exist in billing (TWSD Billing `docs/LICENSING.md`, tests in
`tests/Feature/Licensing/StorefrontPaymentLifecycleTest.php`) and are wired here:

| Endpoint | Billing does | musicdesk.tech calls it from |
|---|---|---|
| `POST /customers` (needs `Idempotency-Key`) | Creates the organisation and links it to the storefront → `201` customer | Sign-up and guest checkout (`OrganisationService::syncToBilling`) |
| `POST /orders/{ord}/confirm-payment` | Order → `paid`; its `pending` license → `active`, even past the 14-day grace. Event `payment_confirmed`, webhook `license.updated`. Already paid → `200` unchanged; refunded → `409 order_refunded` | Admin **Mark as paid** for bank transfers |
| `POST /orders/{ord}/refund` | Order → `refunded`. A `new_license` order’s license is revoked with the reason (webhook `license.revoked`); other kinds are only recorded on the license timeline. Repeat → `200` unchanged | Admin **Record refund** (asks for the gateway refund reference and a reason) |

Every order response now also carries `payment_status` (`paid` · `pending` · `refunded`), `paid_at`, `refunded_at`
and `refund_reference`. musicdesk.tech stores billing’s order id (`ord_…`) in `orders.meta.billing_order_id` from the
`POST /orders` response.

**If billing is unreachable** for any of these, the order is flagged in `/admin/orders`, and **Retry in billing**
repeats the outstanding step. All three are idempotent, so retries are safe. **Done in billing by hand** clears the
flag if someone fixed it in billing directly. `X-Acting-User` is the person who acted: the admin for confirmations and
refunds, the customer for their own actions.

### Still open with the billing team

| # | Need | Suggested API |
|---|---|---|
| 7.4 | An agreed owner for renewal-reminder emails | Billing emails on `license.expiring` with a link to `musicdesk.tech/dashboard/licenses`, or musicdesk.tech sends them |
| 7.5 | USD amount on orders (optional, for revenue reports) | Accept `amount_usd` on `POST /orders`; musicdesk.tech already keeps the exchange rate on each invoice |
| 7.6 | Release download URLs | `POST /licenses/{lic}/downloads` `{ version }` → `{ url, expires_at, sha256 }` |

---

## 8. Not yet wired on musicdesk.tech (UI exists, waiting on decisions)

- **Renewals, upgrades, extra domains and switching to lifetime, bought from the portal.** Billing accepts them (`kind: renewal | updates_renewal | extra_domains | change_plan | convert_lifetime` with `license_id`). musicdesk.tech's checkout currently sells new licenses only. The next step is a checkout for these order kinds, which `HttpBillingClient::submitOrder` already supports through `orders.meta.billing_kind` and `meta.license_id`.
- **License certificate PDF** and **offline `.ldlic` activation**: not in billing's phase 1.
- **License history timeline**: `GET /licenses/{lic}/events` is available but not shown yet.

---

## 9. Testing

`php artisan test` runs 37 tests, including `tests/Feature/BillingIntegrationTest.php`. That file uses billing's exact response shapes (faked HTTP) to check:
- the `POST /orders` body, headers, idempotency key and minor-unit amount
- the customer id stored from the first order and reused afterwards
- key reveal, domain errors, webhook cache clearing, storefront filtering and signature rejection
- `POST /customers` at sign-up, and the fallback to creating the customer with the first order
- bank-transfer confirmation and refunds (`confirm-payment`, `refund`), including the flag and **Retry in billing** when billing is down

### End-to-end on staging
1. Connect a billing staging storefront (§2).
2. Buy with Razorpay test keys. The order appears in billing, and the key reveals in the portal.
3. Add a domain from the portal. It shows in billing as `pending`.
4. Suspend the license in billing. A webhook arrives, the portal shows the suspension banner, and a LabelDesk install is refused on its next check-in.
5. Reinstate it.
