# Changelog

Changes to MusicDesk.tech (sales site, customer portal, site admin and LabelDesk license server).
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [2.0.2] — 2026-11-27

### Added
- **Create order** in Admin → Orders: for phone or email sales of any kind (new license, renewal, updates
  renewal, extra domains, upgrade, switch to lifetime, add-on). Priced at list price with the customer's tax,
  or a custom total. *Paid already* applies it at once (license created or changed, invoice paid); otherwise
  it waits for payment like a bank transfer.
- **Sign in as customer** (icon on Admin → Customers): opens the customer portal as the organisation's owner,
  with a *Return to admin* banner. New team permission *Sign in as customers*.
- **Sign in to a customer's LabelDesk as Super Admin** (Licenses › Status, for every live or failing domain,
  and each domain on a license or add-on page). Opens the site in a new tab with a signed, single-use token
  valid 2 minutes; recorded in the license history. Needs LabelDesk 4.3.4 (see
  `docs/labeldesk-changes-2.0.2.md`). New team permission *Sign in to customer sites*.
- Licenses › Status: add-on licenses show an *Add-on* badge and the add-on's name.

### Changed
- Payment gateways page is a single list: one line per gateway with its status, mode and currencies, an
  on/off switch that saves at once, and the keys, mode and currencies when you click it.
- The "⋯" menu on Admin → Orders opens on top of the page instead of inside the table, so it is never cut off.

### Removed
- "Local test accounts are listed in the README." from the sign-in page.

## [2.0.1] — 2026-11-26

### Added
- **Free trials** on pricing plans (Admin → Pricing plans → *Offer a free trial of this plan*). Set the trial
  length in days and its limits: production domains, maximum users, artists, releases and tracks, whether
  the plan's add-ons are included, and a note shown to customers ("Other limitations").
- Pricing page: *Start N-day free trial* under each plan that offers one, with the limits. `/trial/{plan}`
  creates the account (or uses the signed-in one) and a trial license straight away, no payment.
  One free trial per customer account, and a domain can run only one trial (`trial_used`).
- Trial licenses (`billing_type: "trial"`) carry `trial: true` and `limits` in the license token and the
  activate / check-in response, for LabelDesk to enforce (see `docs/labeldesk-changes-2.0.1.md`).
- Buying the plan turns the trial into the paid license: same key, same domains, limits lifted. This also
  works after the trial ended or was suspended.
- When a trial ends it follows the same steps as an expired annual license: LabelDesk is read-only for
  10 days, then locked, and the trial license is suspended ("Free trial ended"). Emails 3 days before the
  end, at the end and at suspension (`licenses:end-trials`, hourly).
- Customer portal: trial banner with days left, limits and *Buy*; renew / upgrade / extra domains / add-on
  purchases are replaced by *Buy the plan* on a trial.
- **Delete orders, licenses and customers** (Admin → Orders / license page / customer page). Each needs
  the order number, license number or customer name typed to confirm, and the new team permission
  *Delete records* (Owner has it; give it to other roles under Team & roles).
  - Order: optionally with its invoices; a license it created is kept.
  - License: with its add-on keys, domains and history; installs using it lock at their next check-in.
  - Customer: organisation, user accounts, licenses, orders, invoices and support tickets. Refused while a
    team member belongs to the organisation.
- Admin sidebar shows the MusicDesk.tech version.

### Changed
- Release changelog has no length limit, shows a live character and entry count, and is read from the
  `CHANGELOG.md` in the uploaded package when left empty.

## [2.0.0] — 2026-11-24

### Added
- Licensing, billing and invoices in the application (billing.twsd.me no longer used), customer portal,
  site admin, web installer.
- License server for LabelDesk: activation, check-in, deactivation with emailed codes (4.3.2), security
  reports and Licenses › Status, licensed add-ons with their own keys, over-the-air updates with signed
  packages (4.3.3).
- Support tickets, admin team roles and permissions.
