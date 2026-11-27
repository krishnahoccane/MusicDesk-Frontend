# LabelDesk changes needed for MusicDesk.tech 2.0.2

MusicDesk.tech 2.0.2 lets the MusicDesk support team **sign in to a customer's LabelDesk as its Super Admin**
with one click (Admin → Licenses › Status, and each license page, for main licenses and add-ons). This works
for any domain, also ones whose license check fails, is inactive or was never licensed, because LabelDesk
verifies the sign-in offline with the public key it already ships with.

Suggested LabelDesk version: **4.3.4** (together with the free-trial changes in `labeldesk-changes-2.0.1.md`).
Until LabelDesk has this, the button opens the site's `/musicdesk/sso` page, which doesn't exist yet (404).

---

## 1. The request

The admin's browser POSTs a form (not a link, so the token is not in the URL, browser history or logs):

```
POST https://<domain>/musicdesk/sso
Content-Type: application/x-www-form-urlencoded

token=<EdDSA JWT>
```

`http://` is used only for `localhost`, `*.localhost`, `*.test` and `127.x` domains.

## 2. The token

A JWT signed with the **license signing key** (header `{"alg":"EdDSA","typ":"JWT","kid":"…"}`, same key and
`kid` as license tokens, verified with the key LabelDesk ships / `GET /public-key`). Claims:

| Claim | Meaning |
|---|---|
| `typ` | Always `"admin_sso"`. Refuse anything else (a license token must never sign anyone in) |
| `iss` | `https://musicdesk.tech` (must match, as for license tokens) |
| `aud`, `dom` | The domain the sign-in is for. Must equal this site's domain (normalised: lower case, no `www.`, no port) |
| `iat`, `exp` | Issued at / expires: **2 minutes**. Allow at most 30 seconds of clock skew |
| `jti` | Unique id (UUID). **Single use**: store used ids until `exp` (cache or table) and refuse a repeat |
| `lic`, `act` | License and activation ids when known (`null` for unlicensed / unknown domains) |
| `addon` | Add-on code when the sign-in was started from an add-on license (`null` otherwise) |
| `by`, `by_email` | The MusicDesk team member, for the audit log |
| `reason` | Optional free text, for the audit log |

## 3. What LabelDesk does

1. Route `POST /musicdesk/sso`, outside the login, license and maintenance checks, **without CSRF**
   (the request comes from musicdesk.tech; the signed token is the protection). Rate limit 10/minute per IP.
2. Verify the token: signature with the shipped public key, `typ`, `iss`, `aud`/`dom` = this domain, `exp`,
   `jti` not used before. Any failure: show a plain "This sign-in link is not valid or has expired" page
   (HTTP 403), log it, and do not reveal which check failed.
3. Sign in as the **active Super Admin**: the first active user with the Super Admin role (lowest id).
   If there is none, show "No active Super Admin on this site" (403).
4. Works **whatever the license state is**: no license, invalid, expired, read-only, suspended, revoked, locked,
   blocked. The support session can then see the license screen and fix it (enter a key, etc.). When the
   site is locked, the session may open Settings → License and the System pages.
5. Mark the session as a support session (`musicdesk_support = by_email`):
   - a banner on every page: *"Signed in by MusicDesk support ({by}). Sign out when done."*
   - the session ends after **60 minutes** or at sign-out; it is not "remembered".
   - do not update the Super Admin's "last login" time, password or 2FA settings.
6. **Audit log** entry (Administration → Activity / audit log): *"MusicDesk support ({by}, {by_email}) signed in as
   {user}"* with IP, `lic`, `addon`, `reason`. Email the Super Admin: *"MusicDesk support signed in to your
   LabelDesk on {date} for support."* (can be turned off in Settings → Security, on by default).
7. Optional setting **Settings → Security → "Allow MusicDesk support to sign in"** (on by default). When off,
   refuse with "Support sign-in is turned off on this site" (403).

Add-ons: a sign-in started from an add-on license is the same LabelDesk sign-in (add-ons run inside
LabelDesk); `addon` is only recorded.

## 4. Security notes

- Never accept the token by GET.
- Use `hash_equals`-style comparisons and the same JWT verifier as license tokens, but a separate code path:
  the `typ` check is what stops a license token from being used to sign in.
- The `jti` store must survive across PHP workers (database or the configured cache).
- Don't redirect to any URL taken from the request after sign-in; always go to the admin dashboard.

## Checklist

- [ ] `POST /musicdesk/sso` (no CSRF, rate limited, works when unlicensed / locked)
- [ ] Token checks: signature, `typ = admin_sso`, `iss`, `aud`/`dom`, `exp` (≤ 2 min, 30 s skew), single-use `jti`
- [ ] Sign in as the first active Super Admin
- [ ] Support banner, 60-minute session, no "last login" / password changes
- [ ] Audit log entry and email to the Super Admin
- [ ] Setting "Allow MusicDesk support to sign in" (default on)
- [ ] CHANGELOG entry (LabelDesk 4.3.4)
