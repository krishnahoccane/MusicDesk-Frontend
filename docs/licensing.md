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

**Expired annual licenses**: LabelDesk becomes read-only on the expiry date, stays readable for 10 days (sign-in still works), and from day 11 the install is locked (no sign-in) until the license is renewed. Updates, downloads and support pause until renewed. Suspended and revoked licenses, and pending ones past the 14-day window, stop working at the next check-in.

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
| `/admin/licenses/status` | **Status**: every live domain (online / silent / not installed yet) and every failed license check, one row per domain. Unknown domains get **Issue license** (opens the issue form with the domain filled in and closes the alert on save); any domain can be **blocked** (also deactivates it on a license), dismissed or unblocked. **Block a domain** blocks one before it reports anything |
| `/admin/support` | Support tickets: needs reply / awaiting customer / resolved, priority, assignee, reply or internal note, attachments. Customers are emailed each public reply |
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

Token claims: `iss`, `iat`, `exp`, `lic` (license id), `act` (activation id), `dom`, `env`, `plan`, `status`, `support_until`, `expires` (license expiry, Unix time; `null` for lifetime), `max_version`. The JSON response also carries `expires_at` (ISO 8601).

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
| `domain_blocked` | 403 | Domain was blocked under Licenses › Status |
| `invalid_token` | 401 | Bad signature or malformed token |
| `token_expired` | 401 | Token expired where a fresh one is required; activate again with the key |

### `POST /report` (security webhook)

LabelDesk calls this whenever its **own** license check fails, so the site admins hear about nulled or cracked copies. 10 requests per minute per IP; send it at most once an hour per reason from each install.

```json
{
  "domain": "portal.example.com",          // required: the domain LabelDesk is running on
  "reason": "signature_invalid",           // required, see below
  "key": "LDSK-…",                         // optional: the key the install has (never stored, only matched)
  "token": "eyJ…",                         // optional: the stored license token
  "version": "4.3.0", "php_version": "8.3.12", "instance_id": "…",
  "message": "Signature check failed in LicenseGuard",
  "details": { "path": "app/Licensing/Guard.php" }   // optional, flattened to 20 short values
}
```

Reasons: `no_license`, `invalid_key`, `invalid_token`, `token_expired`, `signature_invalid`, `domain_mismatch`, `license_revoked`, `license_suspended`, `license_expired`, `activation_not_found`, `activation_deactivated`, `files_tampered`, `check_in_failed`, `other` (anything unknown is stored as `other`).

Response `202`:

```json
{ "received": true, "action": "lock", "message": "This domain is blocked from using LabelDesk." }
```

`action` is `lock` when the domain is blocked or the key belongs to a revoked license, otherwise `none`. LabelDesk should lock itself on `lock`.

What happens here:

- One alert per domain under **Licenses › Status**, counting attempts and each reason. The license server's own refusals (`invalid_key`, `invalid_token`, `domain_blocked`, `domain_in_use`, suspended / revoked / expired licenses, deactivated or unknown activations) are recorded the same way, so a copy that never calls `/report` still shows up when it tries to activate. A plain `domain_limit_reached` is not a security event and isn't recorded.
- Every site admin (`is_admin`) is emailed when a domain is first seen, when a dismissed or issued domain fails again, and then at most once a day while it keeps failing. Blocked domains are counted silently. At most 30 alert emails an hour in total, so fake reports can't flood the inbox.
- The endpoint is public by design (a cracked copy has no valid credentials). Anything it receives is treated as a lead, not proof: check the domain before blocking a customer.

**Limits.** A determined cracker can strip the report call out of the code or block outbound traffic. The server-side refusals and the "Silent > 7 days" count on the Status page catch some of those; obfuscating the license check in LabelDesk builds helps with the rest.

### Offline installs

From the portal (Licenses → Offline activation) a customer downloads a `.ldlic` file for one domain: a token valid for 365 days, for servers without outbound internet.

### Signing key

The Ed25519 key pair is generated on first use and stored in the `settings` table, encrypted with `APP_KEY`. **Changing `APP_KEY` makes the stored key unreadable** and the license server returns errors. To recover, delete the `licensing.signing_key` row from `settings` (a new pair is generated on the next request); every install then has to activate again with its key and offline files must be reissued. Back up `APP_KEY` together with the database.
