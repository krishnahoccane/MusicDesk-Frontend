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
- A domain can be live on one license at a time. Exception: while **changing the license key**, the same installation (same `instance_id`) may activate the new key on a domain that is still active on the old key; it then releases the old key with `reason: "key_change"`, which frees that slot.
- Deactivating from LabelDesk (4.3.2+) needs a one-time code emailed to the license owner (see `POST /deactivate`). Deactivating from the portal or the admin needs no code.
- A license can move a domain to a new one at most 3 times per 30 days (portal: Licenses → Move).

## Add-ons

Licensed add-ons are optional LabelDesk modules (e.g. DDEX delivery, SSO). They're set up under **Pricing plans › Licensed add-ons**: name, code, annual price (USD), optional lifetime price, and the plans that include it free.

- **Every add-on is its own license with its own key** (`LDAD-XXXX-XXXX-XXXX-XXXX`), attached to a main license (`parent`). Keys are hashed, encrypted, revealed with an audit entry and regenerated exactly like main keys.
- **Domains:** an add-on has no domain slots. Its key activates only on domains that are live on the main license, and a domain deactivated (or moved) on the main license is deactivated on its add-ons too. Each add-on's active domains are listed in the portal (Licenses › Add-ons) and on the admin license page.
- **Bought add-ons** (portal: license menu › *Buy an add-on*, or the *Add* button on the Add-ons tab) are annual (one year, renew on their own) or lifetime (12 months of updates, then *Renew updates* at 60% of the lifetime price). The key is issued when payment arrives; refunding the order revokes it.
- **Included add-ons:** when a license is bought on, or upgraded to, a plan that includes an add-on, a separate key is issued automatically at no charge. If the customer had already bought that add-on, the same key is kept and marked included. Included add-ons follow the main license's dates and status (renewed, suspended or revoked together) and get no reminders of their own. If the plan stops including it, the included key is suspended at the license's next change. Tick *Also issue keys now to existing licenses* when saving an add-on to give existing licenses on those plans their keys straight away.
- **Admin:** the license page lists its add-ons and can issue one by hand (free of charge; record a sale from Orders if they paid). Add-on licenses appear in the Licenses list with an *Add-on* badge.
- **Releases:** a release belongs to LabelDesk or to an add-on (*Product* on the release form). Versions are unique per product. Add-on packages can only be downloaded with that add-on's key, within its updates period, and are listed under Downloads › Add-on packages.
- **LabelDesk plugins:** the add-on codes `bulk_upload`, `rest_api`, `incoming_webhooks` and `sso` are LabelDesk plugins (the code is `addon` in the plugin's `plugin.json`). A LabelDesk install activates them with the add-on key through the [add-on API](#add-on-api-labeldesk-plugins) below. Their releases must be plugin ZIPs (see [Add-on packages](#add-on-packages)).

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
| `/admin/plans` | Plans, **licensed add-ons** (prices, included plans), services and coupons |
| `/admin/releases` | Publish versions (of LabelDesk or of an add-on) with changelog, requirements and the private `.zip` package (SHA-256 recorded). Add-on packages are checked, and signed if needed, on upload |

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

### `POST /deactivate/code` (LabelDesk 4.3.2+)

`{ "token": "…" }` → `200`

```json
{ "sent": true, "sent_to": "k•••@a•••.com", "expires_in": 600 }
```

Emails a one-time 6-digit code to the license owner: the organisation's owner account, or the organisation's billing email if it has none. The address is never returned in full. The token is verified like `/check-in` (expired tokens accepted; the activation must exist and be active).

- The code is random, stored hashed on the activation, valid 10 minutes and single use. A new request replaces the previous code.
- At most 3 requests per activation per 15 minutes (`rate_limited`, 429). After 5 wrong codes the code is void.
- Recorded in the license activity as "Deactivation code sent" with the domain, IP and masked address (portal: license *Details & history*; admin: license page *History*).
- Email subject: "Your LabelDesk deactivation code" (uses the product name from Branding).

### `POST /deactivate`

```json
{ "token": "…", "code": "123456", "reason": "manual" }
```

→ `204`. Frees the domain (and the same domain on the license's add-ons).

| `reason` | Sent when | Code |
|---|---|---|
| `manual` (default) | The administrator clicks Deactivate | **Required** |
| `transfer` | *Move to a new domain*: the same installation just activated another domain of this license | Not needed |
| `key_change` | *Change license key*: the same installation just activated a different license key | Not needed |

`transfer` and `key_change` are accepted without a code only when the token's activation has an `instance_id` and that same installation made a successful `/activate` in the last 10 minutes (`transfer`: another domain of the same license; `key_change`: another main license key). Otherwise they are treated as `manual` and need the code. The reason and IP are recorded in the license activity.

Changing to a key from **another license**: the old license's add-on keys (`LDAD-…`) stop on that domain at their next hourly check-in with `addon_domain_not_licensed`, because add-ons run only on their main license's domains. The portal's Add-ons tab says so.

### `GET /public-key`

```json
{ "alg": "EdDSA", "crv": "Ed25519", "kid": "…", "public_key": "<base64>" }
```

Installs should ship with this key and verify tokens locally, so they keep working for the token's lifetime if the license server is unreachable.

Token claims: `iss`, `iat`, `exp`, `lic` (license id), `act` (activation id), `dom`, `env`, `plan`, `status`, `support_until`, `expires` (license expiry, Unix time; `null` for lifetime), `max_version`, `addon` (add-on code, `null` for the main license) and `parent` (the main license id, for add-on keys). The JSON response also carries `expires_at` (ISO 8601).

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
| `addon_domain_not_licensed` | 409 | Add-on key used on a domain that isn't live on its main license |
| `domain_blocked` | 403 | Domain was blocked under Licenses › Status |
| `invalid_token` | 401 | Bad signature or malformed token |
| `token_expired` | 401 | Token expired where a fresh one is required; activate again with the key |
| `code_required` | 422 | `/deactivate` with `reason: manual` (or an unconfirmed transfer / key change) and no code |
| `invalid_code` | 422 | Wrong, expired, already used or voided (5 wrong tries) deactivation code |
| `rate_limited` | 429 | More than 3 deactivation code requests for an activation in 15 minutes |
| `no_owner_email` | 422 | The license's organisation has no email to send a deactivation code to |

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
- Every team member whose role includes **License status & security** is emailed when a domain is first seen, when a dismissed or issued domain fails again, and then at most once a day while it keeps failing. Blocked domains are counted silently. At most 30 alert emails an hour in total, so fake reports can't flood the inbox.
- The endpoint is public by design (a cracked copy has no valid credentials). Anything it receives is treated as a lead, not proof: check the domain before blocking a customer.

**Limits.** A determined cracker can strip the report call out of the code or block outbound traffic. The server-side refusals and the "Silent > 7 days" count on the Status page catch some of those; obfuscating the license check in LabelDesk builds helps with the rest.

## Add-on API (LabelDesk plugins)

LabelDesk (`App\Plugins\AddonLicenseService`) activates each installed add-on with that add-on's own key (`LDAD-…`; LabelDesk accepts 8–64 letters, digits and dashes, case-insensitive). Same base URL, JSON, error format and rate limit (30/minute per IP, shared with the license API) as above.

| Method | Path | Body | Success |
|---|---|---|---|
| POST | `/addons/activate` | `{key, addon, domain, version, php_version, instance_id}` | `200 {token, status, expires_at, support_until, addon}` |
| POST | `/addons/check-in` | `{token, version}` (every hour; expired tokens accepted) | `200 {token, status, expires_at, support_until, addon}` |
| POST | `/addons/deactivate` | `{token}` | `204` (frees the domain on the add-on key) |

Rules:

- `addon` is the add-on code. A key for another add-on, or a main `LDSK-` key, gets `addon_mismatch` (409).
- The domain must be live on the add-on's main license (`addon_domain_not_licensed`, 409, otherwise). Add-ons have no domain slots of their own; a domain deactivated or moved on the main license is deactivated on its add-ons. Re-activating a domain already on the key returns a new token.
- An add-on stops (`license_suspended` / `license_revoked` / `license_expired`, 403) when its key is suspended, revoked, expired or pending past 14 days, or when its main license may not be used. Unlike LabelDesk itself, an expired add-on key does not keep running.
- `status` is `active` or `expiring` (within 30 days of expiry). A pending key inside its 14-day window reports `active`.
- Add-on tokens and main license tokens are not interchangeable (`invalid_token`, 401).
- Other codes as for the license API: `invalid_key`, `invalid_domain`, `domain_blocked`, `activation_not_found`, `activation_deactivated`, `invalid_token`, `validation_failed`. Refusals are recorded under Licenses › Status like license refusals.

Token: EdDSA JWT signed with the same key and `kid` as license tokens (`GET /public-key`), valid 30 days. Claims: `iss` (`APP_URL`, must be `https://musicdesk.tech` in production: LabelDesk compares it), `iat`, `exp`, `typ: "addon"`, `addon`, `dom`, `act` (activation id), `status`, `expires` (Unix time of the key's expiry, `null` for lifetime), `lic` (add-on license id). LabelDesk lets an add-on run until `exp` + 14 days without reaching the server.

## Add-on packages

Releases of an add-on (*Product* on the release form) take the LabelDesk plugin ZIP as their package:

- **Signed build:** run `php scripts/build-plugin.php addons/<slug> --key=<plugin-signing.key>` in LabelDesk and upload `build/plugins/<slug>-<version>.zip`. The signature and every file hash are checked against the plugin public key (`PLUGIN_PUBLIC_KEY`, default the key compiled into LabelDesk, kid `7491a41a211116b1`).
- **Signed here:** upload the plugin folder zipped (with or without the `<slug>/` folder, no `signature.json`). The server repackages it as `<slug>/…`, leaves out `tests/`, `.git/`, `node_modules/`, signs it with the key file at `PLUGIN_SIGNING_KEY_PATH` (default `storage/app/private/plugin-signing.key`, base64 Ed25519 secret key) and checks the result the way LabelDesk will. Refused if the key file is missing or doesn't match the public key.
- Either way, `plugin.json` must name the release's add-on code and version, the folder must be named after the slug, and file names are checked (no links, `..`, absolute paths; 5,000 files / 200 MB at most).

Customers holding a key for the add-on see its packages under Downloads › Add-on packages (latest first) and can download releases published within the key's updates period. `/account/addons` (the link LabelDesk shows administrators) opens that list.

The signing key file is a secret: keep it out of git (the `storage/app/private` folder is ignored), never paste it into the admin, and back it up separately. Losing it means new add-on versions can only be signed after a LabelDesk release with a new public key.

### Offline installs

From the portal (Licenses → Offline activation) a customer downloads a `.ldlic` file for one domain: a token valid for 365 days, for servers without outbound internet.

### Signing key

The Ed25519 key pair is generated on first use and stored in the `settings` table, encrypted with `APP_KEY`. **Changing `APP_KEY` makes the stored key unreadable** and the license server returns errors. To recover, delete the `licensing.signing_key` row from `settings` (a new pair is generated on the next request); every install then has to activate again with its key and offline files must be reissued. Back up `APP_KEY` together with the database.
