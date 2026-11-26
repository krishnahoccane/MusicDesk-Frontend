# LabelDesk changes needed for MusicDesk.tech 2.0.1

MusicDesk.tech 2.0.1 adds **free trials** of pricing plans and lets the MusicDesk team **delete licenses**.
The license server changes are live on MusicDesk.tech; this is what LabelDesk needs to do with them.
Suggested LabelDesk version: **4.3.4**. Older LabelDesk versions keep working: they ignore the new token
claims, so a trial behaves like a short annual license without limits.

Base URL and error format are unchanged: `https://musicdesk.tech/api/license/v1`.

---

## 1. Trial licenses in the token and responses

A trial is an ordinary license key (`LDSK-…`). `/activate` and `/check-in` return two new fields, and the
license token (EdDSA JWT, same key and `kid`) carries the same two claims:

```json
{
  "token": "…",
  "status": "active",
  "expires_at": "2026-12-10T09:30:00Z",
  "support_until": "2026-12-10T09:30:00Z",
  "trial": true,
  "limits": { "domains": 1, "users": 3, "artists": 25, "releases": 50, "tracks": null, "addons": false },
  "messages": ["Free trial: 14 days left (ends Dec 10, 2026). Buy a license to keep your data and settings."]
}
```

| Claim / field | Meaning |
|---|---|
| `trial` | `true` while the license is a free trial; `false` for paid licenses |
| `limits` | Only on trials (`null` otherwise). `domains` = production domains (the server enforces it). `users`, `artists`, `releases`, `tracks`: maximum count, `null` = no limit. `addons`: whether the plan's add-ons come with the trial |
| `expires` / `expires_at` | End of the trial |
| `status` | `active`, `expiring` (last 3 days of a trial), `expired`, then `suspended` |
| `plan` | Plan code, as before (for the *Buy* link) |

Trust the claims in the verified token, not the JSON fields, as for the other claims.

## 2. Enforce the limits

While `trial` is `true`:

- **Users, artists, releases, tracks**: refuse to create one more when the count has reached the limit.
  Count what exists in the database (deleted records don't count). Show *"Your free trial allows up to
  25 artists. Buy LabelDesk {Plan} to add more."* with the buy link (section 4). Existing records stay
  usable; importing (bulk upload, CSV, API) must respect the limit too and stop at it with the same message.
- **Add-ons**: when `limits.addons` is `false` the plan's add-ons are not issued for the trial; the
  Add-ons page shows "Add-ons are not included in the free trial".
- **Domains**: nothing to do; activation on more production domains is refused by the server
  (`domain_limit_reached`).
- Limits apply from the next check-in. When `trial` becomes `false` (bought, see 5) all limits are lifted
  at once; no new key is needed.

## 3. When the trial ends: same as an expired license

The same steps as an annual license past its expiry:

1. **Ends** (`expires` passed, `status: "expired"`): LabelDesk is **read-only**: sign-in and viewing work,
   changes are refused. Banner: *"Your free trial has ended. LabelDesk is read-only until {expires + 10 days},
   then it locks. Buy a license to continue."* The server's `messages` carry this text.
2. **expires + 10 days**: LabelDesk **locks** (as for an expired annual license; use `expires`, not the
   server, so it also happens offline).
3. MusicDesk.tech then **suspends** the trial license (reason "Free trial ended"); check-ins answer
   `403 license_suspended`. Show the locked page with the *Buy* link instead of "contact support" when
   the token has `trial: true`.

Data is never deleted by LabelDesk; buying restores everything.

## 4. Trial banner and buy link

- A banner on every admin page while `trial` is `true`: *"Free trial: N days left · Buy LabelDesk {Plan}"*.
  N from `expires` (round up). Use `expiring` (last 3 days) for a stronger colour.
- Buy link: `https://musicdesk.tech/checkout/{plan}` (`plan` claim). Signed-in customers buying the plan
  get the trial converted (section 5).
- Settings → License shows "Free trial until {date}" and the limits with current usage
  (e.g. "Artists 12 / 25").

## 5. Buying converts the trial

When the customer buys the plan, MusicDesk.tech turns the trial license into the paid license: **same key,
same domains**. The next check-in returns `trial: false`, `limits: null` and the paid `expires_at`
(annual) or `null` (lifetime). If the trial was already suspended, the license is active again and
LabelDesk unlocks at that check-in. The *Check again* / *Request check-in* buttons speed this up.

## 6. New activation error

| Code | HTTP | When | Show |
|---|---|---|---|
| `trial_used` | 409 | A trial key is activated on a domain that already ran a free trial (on another account) | The server's message: "This domain has already run a free trial. Buy a license to use LabelDesk on it." |

## 7. Deleted licenses

The MusicDesk team can now delete a license (and a customer with all their licenses). Installs using it
get `404 activation_not_found` at the next check-in, and a new activation with the old key gets
`404 invalid_key`. LabelDesk already handles both (read-only / locked, enter a key); check that the
message suggests contacting MusicDesk.tech support.

## Checklist

- [ ] Read `trial` and `limits` from the token; lift limits when `trial` is false
- [ ] Enforce users / artists / releases / tracks limits (forms, imports, API) with the buy link
- [ ] No add-ons during a trial unless `limits.addons`
- [ ] Trial banner with days left; stronger in the last 3 days
- [ ] Trial end: read-only, locked after 10 days; locked page with *Buy* when `trial: true`
- [ ] `trial_used` activation error
- [ ] Settings → License: trial end date and usage against limits
- [ ] CHANGELOG entry (LabelDesk 4.3.4)
