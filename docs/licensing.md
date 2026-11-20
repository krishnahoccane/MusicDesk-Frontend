# Licensing & billing

MusicDesk sells, issues and administers LabelDesk licenses itself. There is no external billing system: orders, invoices, license keys, domains, suspensions and downloads all live in this application.

## Licenses

| Field | Notes |
|---|---|
| Number | `LIC-XXXXXX`, shown to customers and on invoices |
| Key | `LDSK-XXXX-XXXX-XXXX-XXXX`. Stored as a SHA-256 hash (for look-ups) and encrypted with `APP_KEY` (so it can be revealed). Every reveal is recorded in the license history. |
| Billing type | `annual` (expires after a year, renews) or `lifetime` (no expiry, 12 months of updates included) |
| Domains | Plan's production domains + purchased extra domains. Staging domains are free and unlimited. |
| Updates until | `support_until`. Releases published after this date can't be downloaded or installed. |
| Status | `pending` (bank transfer not yet received, works for 14 days), `active`, `suspended`, `revoked` (permanent). Shown as `expiring` within 30 days of expiry and `expired` after. |

**Expired annual licenses keep running**: updates, downloads and support pause until renewed. Suspended and revoked licenses, and pending ones past the 14-day window, stop working at the next check-in.

### Domain rules

- Domains are normalised (scheme, `www.`, path and port removed, lower-case).
- `staging.`, `dev.`, `test.` sub-domains, `.test` / `.local` / `.localhost` names and IP addresses count as staging.
- A domain can be live on one license at a time.
- A license can move a domain to a new one at most 3 times per 30 days (portal: Licenses → Move).

## Purchases

| Kind | Price |
|---|---|
| New license | Plan price (annual or lifetime) + extra domains + add-ons |
| Renewal (annual) | Plan annual price + extra domains for another year, from the later of today and the current expiry |
| Updates renewal (lifetime) | 60% of the plan's annual price, 12 more months of updates |
| Extra domains | Annual: prorated to the renewal date. Lifetime: one-time, 2.5× the yearly extra-domain price |
| Upgrade plan | Annual: prorated price difference to the renewal date. Lifetime: lifetime price difference |
| Switch to lifetime | Lifetime price minus the unused part of the current year |

Card / Razorpay / PayPal payments apply the change as soon as the gateway confirms. Bank transfers create an invoice in `open` status; the change applies when an admin marks the order paid (`/admin/orders`). A new license bought by bank transfer is issued immediately as `pending`.

Refunding an order (`/admin/orders`) revokes the license if it was a new-license order; for other kinds the refund is recorded in the license history for the admin to review.

"Renewal reminders" (`auto_renew`) only controls reminder emails; cards are never charged automatically.

## Admin

| Page | What it does |
|---|---|
| `/admin/licenses` | Search and filter all licenses; issue a license by hand (optionally recording a sale with order + invoice) |
| `/admin/licenses/{id}` | Reveal key, edit plan / domains / dates / version cap / notes, suspend / reinstate / revoke (type the number to revoke), generate a new key, add or deactivate domains, request a check-in, orders, full history |
| `/admin/customers` | Organisations with team, licenses, orders, invoices and revenue; edit billing details and internal notes |
| `/admin/releases` | Publish versions with changelog, requirements and the private `.zip` package (SHA-256 recorded) |

## Scheduled jobs

Add `* * * * * php /path/to/artisan schedule:run` to cron.

| Command | When (UTC) | |
|---|---|---|
| `currency:update-rates` | 00:05 | Exchange rates |
| `licenses:notify-expiring` | 08:00 | Renewal reminders 30, 14 and 3 days before an annual license expires (only the most urgent one due, never twice; skipped when reminders are off). `--dry-run` lists without sending. |

## License server API (for LabelDesk installs)

Base URL `https://<your site>/api/license/v1`, JSON, 30 requests per minute per IP. Errors look like:

```json
{ "error": { "code": "domain_limit_reached", "message": "All 3 production domains on this license are in use…", "field": null } }
```

### `POST /activate`

```json
{ "key": "LDSK-…", "domain": "portal.label.com", "environment": "production", "version": "4.3.0", "php_version": "8.3.12", "instance_id": "uuid" }
```

`environment` is optional (detected from the domain). Re-activating a domain already on the license is allowed (new token).

### `POST /check-in` (every 24 h)

```json
{ "token": "…", "version": "4.3.0", "php_version": "8.3.12" }
```

Accepts expired tokens so an install that was offline can recover; returns a fresh token.

### Success response (activate and check-in)

```json
{
  "token": "eyJ…",                 // Ed25519-signed JWT, valid 30 days
  "status": "active",              // active | expiring | expired | pending
  "support_until": "2027-03-14T10:00:00Z",
  "max_version": null,             // newest version allowed, null = any
  "check_in_requested": false,
  "messages": []                   // show these to the customer's admin
}
```

### `POST /deactivate`

`{ "token": "…" }` → `204`. Frees the domain.

### `GET /public-key`

```json
{ "alg": "EdDSA", "crv": "Ed25519", "kid": "…", "public_key": "<base64>" }
```

Installs should ship with this key and verify tokens locally, so they keep working for the token's lifetime if the license server is unreachable.

Token claims: `iss`, `iat`, `exp`, `lic` (license id), `act` (activation id), `dom`, `env`, `plan`, `status`, `support_until`, `max_version`.

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_key` | 404 | Key not recognised (or replaced by a new key) |
| `invalid_domain` | 422 | Not a valid domain |
| `domain_limit_reached` | 409 | All production domains used |
| `domain_in_use` | 409 | Domain is live on another license |
| `license_suspended` / `license_revoked` / `license_expired` | 403 | License may not be used (pending past 14 days reports `license_expired`) |
| `activation_not_found` | 404 | Token's activation no longer exists |
| `activation_deactivated` | 403 | Domain was deactivated; activate again with the key |
| `invalid_token` | 401 | Bad signature or malformed token |
| `token_expired` | 401 | Token expired where a fresh one is required; activate again with the key |

### Offline installs

From the portal (Licenses → Offline activation) a customer downloads a `.ldlic` file for one domain: a token valid for 365 days, for servers without outbound internet.

### Signing key

The Ed25519 key pair is generated on first use and stored in the `settings` table, encrypted with `APP_KEY`. **Changing `APP_KEY` makes the stored key unreadable** and the license server returns errors. To recover, delete the `licensing.signing_key` row from `settings` (a new pair is generated on the next request); every install then has to activate again with its key and offline files must be reissued. Back up `APP_KEY` together with the database.
