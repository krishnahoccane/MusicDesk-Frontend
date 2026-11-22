# UI & UX guide

The design system used by musicdesk.tech (sales site, customer portal, site admin), written so it can be carried over to **LabelDesk** as is.

Stack: Laravel Blade components · Tailwind CSS v4 (`@theme` tokens + `@layer components`) · Alpine.js (+ `@alpinejs/collapse`) · no React, no UI kit. Source of truth: `resources/css/app.css`, `resources/js/app.js`, `resources/views/components/`.

---

## 1. Look and feel

- **Dark only.** A near-black, slightly violet canvas (`ink-950`) with frosted-glass cards. `color-scheme: dark`.
- **One brand colour does the work** (violet `volt`), with a second accent (pink `flare`) used only in gradients and highlights. Status colours (mint, amber, coral, sky) appear only for meaning.
- **Quiet surfaces, loud moments.** Data screens are calm: hairline borders, muted labels, white numbers. Colour, glow and gradient text are reserved for the hero, primary buttons, and "something new" callouts.
- **Three typefaces with clear jobs:** a tight grotesque for UI, an italic serif for one emotional word per headline, a mono for labels, numbers and codes.
- **Depth through light, not shadow:** blurred radial glows behind sections, a 1px inner top highlight on cards and buttons, a faint film-grain noise over the whole page.

---

## 2. Design tokens

All tokens are Tailwind v4 `@theme` variables, so every utility (`bg-volt-500`, `text-ink-400`, `ring-mint-500/20`) comes from them.

### 2.1 Neutrals: `ink`

| Token | Hex | Used for |
|---|---|---|
| `ink-950` | `#07070b` | Page background, sidebar, inputs (at 60%) |
| `ink-900` | `#0b0b12` | Cards (at 70% + blur), modals |
| `ink-850` | `#10101a` | Raised areas inside cards |
| `ink-800` | `#151521` | Hover fills on dark areas |
| `ink-700` | `#1d1d2c` | Tracks, progress backgrounds |
| `ink-600` | `#2a2a3d` | Switch off state, scrollbar thumb |
| `ink-500` | `#4a4a63` | Placeholders, tertiary text, disabled icons |
| `ink-400` | `#7d7d98` | Secondary text, table headers, eyebrows |
| `ink-300` | `#a9a9c0` | Body text in cards, labels |
| `ink-200` | `#d2d2e0` | Default body text |
| `ink-100` | `#ececf4` | Emphasised body text |
| `white` | | Headings, numbers, primary values |

Borders are **white at low alpha**, never grey hex: `border-white/[0.06]` (the `.hairline` class) for structure, `/[0.08]` for inputs, `/10` for glass and modals, `/20` on hover.

### 2.2 Brand and status

| Token | Hex | Role |
|---|---|---|
| `volt-300` | `#b9a8ff` | Links, active icon, text on dark violet |
| `volt-400` | `#9b84ff` | Primary gradient top, focus outline |
| `volt-500` | `#7c5cff` | **Primary brand colour** |
| `volt-600` | `#6440f0` | Primary gradient bottom |
| `flare-400` / `flare-500` | `#ff6be0` / `#ff3fd2` | Secondary accent; admin area tint |
| `amber-glow` | `#ffb547` | Warning, expiring, "awaiting", highlights |
| `mint-400` / `mint-500` | `#4ff0bd` / `#22d9a0` | Success, active, online, positive change |
| `coral-400` / `coral-500` | `#ff7a7a` / `#ff5757` | Danger, errors, suspended, negative change |
| `sky-glow` | `#5cc8ff` | Informational, refunded, neutral data series |

**Coloured fills are always tinted, never solid:** background at `/10`, ring at `/20–25`, text in the `-400` shade. The only solid colour fills are the primary button, avatars and switches.

### 2.3 White-label theming (important for LabelDesk)

Brand colours and fonts are **settings, not code**. The layout prints a `<style>:root{…}</style>` after the CSS bundle that overrides the tokens; lighter and darker steps are derived with `color-mix`:

```css
:root{
  --color-volt-500: #7c5cff;                                   /* admin-chosen */
  --color-volt-300: color-mix(in oklab, #7c5cff 55%, white);
  --color-volt-400: color-mix(in oklab, #7c5cff 80%, white);
  --color-volt-600: color-mix(in oklab, #7c5cff 82%, black);
  --color-flare-500: #ff3fd2;  --color-flare-400: color-mix(in oklab, #ff3fd2 80%, white);
  --color-amber-glow: #ffb547; --color-mint-500: #22d9a0; --color-mint-400: color-mix(in oklab, #22d9a0 80%, white);
  --color-ink-950: #07070b;
  --font-sans: 'Inter Tight', …; --font-serif: 'Instrument Serif', …; --font-mono: 'JetBrains Mono', …;
}
```

Rules that keep this working:
- Never hard-code a brand hex in a view. Use `volt-*` / `flare-*` utilities or `var(--color-…)`. (Exceptions: chart series colours passed as props.)
- Use `color-mix(in oklab, var(--color-volt-500) 30%, transparent)` for glows so they follow the brand.
- Fonts load from one URL built from the chosen families (Bunny Fonts, GDPR-friendly).
- Logo, favicon and social image are optional uploads with built-in fallbacks.

### 2.4 Typography

| Role | Font | Style |
|---|---|---|
| UI and body | `Inter Tight` (`font-sans`) | `font-feature-settings: "ss01", "cv11"`; antialiased |
| Accent word | `Instrument Serif` italic (`.serif-accent`) | One word or short phrase per headline, often with `.text-gradient` |
| Data and labels | `JetBrains Mono` (`font-mono`) | Keys, invoice numbers, versions, prices in tables, eyebrows, table headers |

| Class / size | Spec | Where |
|---|---|---|
| Hero display | `.display text-[44px] sm:text-[64px] lg:text-[84px] leading-[0.98]` | Marketing hero |
| Page title | `.display text-[28px] sm:text-[32px]` | Every app page header |
| `.display` | `font-semibold tracking-[-0.035em] text-white` | All big headings |
| Section title | `font-semibold text-white` (16px) | Card headings |
| Body | `text-sm` (14px) in app, `text-[15px]`–`17px` on marketing | |
| Small | `text-[13px]` labels/nav, `text-xs` meta, `text-[11px]` sub-lines | |
| `.eyebrow` | `font-mono text-[11px] uppercase tracking-[0.2em] text-ink-400` | Above page titles ("Admin · Licensing"), nav group names |
| Stat value | `text-[28px] font-semibold leading-none tracking-tight text-white` | KPI cards |

Hierarchy comes from **colour and weight, not size jumps**: white for values, `ink-300/400` for labels, `ink-500` for meta.

### 2.5 Shape, spacing, depth

- Radius scale: `rounded-lg` (8) small controls and avatars · `rounded-xl` (12) buttons, inputs, nav items · `rounded-2xl` (16) cards, large buttons · `rounded-3xl` (24) modals · `rounded-full` badges and pills.
- Card padding: `.card-pad` = `p-5 sm:p-6`. Gaps between cards: `gap-4`. Page sections: `mb-8` under the header, `mt-4` between card rows.
- Page gutters: `px-4 sm:px-6 lg:px-8`, content capped at `max-w-[1400px]`.
- Card depth: `box-shadow: inset 0 1px 0 rgb(255 255 255 / .04), 0 1px 2px rgb(0 0 0 / .4)`.
- Floating layers (menus, modals, toasts): `shadow-2xl shadow-black/50–70`.
- Z-order: sticky header 30 · mobile backdrop 40 · sidebar 50 · modal 60 · noise overlay 60 (pointer-events none) · toasts 70.

---

## 3. Components

All are plain CSS classes or Blade components, so they work in any server-rendered page.

### 3.1 Surfaces

| Class | Recipe | Use |
|---|---|---|
| `.card` | `rounded-2xl border-white/[0.06] bg-ink-900/70 backdrop-blur-xl` + inset highlight | Every content block |
| `.glass` | `border-white/10 bg-white/[0.04] backdrop-blur-2xl` | Floating: menus, toasts, pills on the hero |
| `.hairline` | `border-white/[0.06]` | Dividers: `border-b hairline`, `border-t hairline` |
| `.glow-border` | 1px gradient border (volt → transparent → flare) via mask | The featured pricing plan, highlighted cards |

Card anatomy: header row `p-5 sm:p-6` with title left and actions right → optional `border-b hairline` → table or body → optional footer `border-t hairline px-4 py-3`.

### 3.2 Buttons

`.btn` + one variant + optional size.

| Variant | Look | When |
|---|---|---|
| `.btn-primary` | Vertical volt gradient, inner highlight, coloured drop glow; hover brightens and lifts 1px | The one main action on a screen |
| `.btn-white` | Solid white, dark text, soft white glow | Marketing hero CTA only |
| `.btn-outline` | Faint fill, `white/10` border | Secondary actions |
| `.btn-ghost` | Transparent, hover `white/[0.06]` | Toolbar icons, Cancel, menu toggles |
| `.btn-danger` | Coral tint fill + border, coral text | Delete, deactivate, revoke, suspend |

Sizes: `.btn-sm` (13px, `rounded-lg`) in tables and card headers · default (14px) in forms · `.btn-lg` (15px, `rounded-2xl`) on marketing. Icon-only: `btn btn-ghost btn-sm !p-1.5` with `aria-label`. Icons sit inside the button before the label, `size-3.5` (sm) or `size-4`.

Focus: `focus-visible:outline-2 outline-offset-2 outline-volt-400`. Disabled: `opacity-50 pointer-events-none`.

### 3.3 Forms

```html
<div>
  <label class="label" for="domain">Domain</label>
  <input id="domain" name="domain" class="input" placeholder="portal.yourlabel.com">
  <p class="mt-1 text-xs text-coral-400">That is not a valid domain.</p>   <!-- error -->
</div>
```

- `.label`: 13px, medium, `ink-300`, 6px below. Optional hint inline in `text-ink-500`: `Version cap <span class="text-ink-500">(blank = by updates date)</span>`.
- `.input`: `rounded-xl`, `bg-ink-950/60`, `border-white/[0.08]`; focus = volt border at 60% + 4px soft volt ring. Same class on `select` (custom chevron) and `textarea`.
- Codes, keys, versions, numbers: add `font-mono`.
- Compact inputs in toolbars: `input !py-1.5 text-sm` or `!py-2 text-[13px]`.
- Checkboxes: native, `size-4 accent-volt-500`, preceded by a hidden `0` input so unchecked posts.
- Toggles: `.switch` button with `aria-checked`, inner `<span>` knob; volt when on.
- Segmented choices (billing period, status): radio + `peer sr-only` + a label box that gets a tinted border/fill when `peer-checked`.
- File inputs: `file:` utilities giving a small `bg-white/10` pill button.
- Layout: forms in a card with `space-y-3`/`space-y-4`; pairs in `grid grid-cols-2 gap-3`; full-width primary submit at the bottom of sidebar forms.

### 3.4 Badges and status

`.badge` + colour, optional `.badge-dot` (leading 6px dot in `currentColor`).

| Class | Meaning |
|---|---|
| `badge-mint` | active, paid, online, published |
| `badge-amber` | expiring, awaiting payment, open, beta |
| `badge-coral` | suspended, failed, overdue |
| `badge-sky` | refunded, informational |
| `badge-volt` | production, plan names, featured |
| `badge-flare` | "ADMIN", new |
| `badge-ink` | pending, cancelled, staging, neutral |

Status text is lower-case, the same word everywhere (one helper maps status → badge class). Small counters in nav: `rounded-md bg-mint-500/15 px-1.5 font-mono text-[10px] text-mint-400`.

Online indicator: `size-2 rounded-full bg-mint-400` (online) / `bg-ink-500` (offline) before the name.

### 3.5 Tables

```html
<div class="card">
  <div class="overflow-x-auto">
    <table class="table">…</table>
  </div>
</div>
```

- Headers: mono, 11px, uppercase, `tracking-[0.14em]`, `ink-400`, hairline under.
- Cells: `px-4 py-3.5`, `whitespace-nowrap`, row divider `white/[0.04]`, last row none, row hover `white/[0.025]`.
- Primary cell: name in `font-medium text-white`, sub-line in `text-[11px] text-ink-500`.
- IDs, keys, money: `font-mono`; money in `text-white`, IDs in `text-xs`.
- Linked IDs: `text-volt-300 hover:text-white`. Whole row clickable on list pages (`cursor-pointer`, link in the first cell for keyboard users).
- Row actions: right-aligned `btn-sm` (`btn-ghost` / `btn-danger`).
- Retired rows (deactivated, deleted): `opacity-50`.
- Empty: one row, `colspan` all, `py-8`–`py-12 text-center text-ink-500`, a sentence that says what will appear there and how.
- Filters sit above the table inside the card: `flex gap-2 border-b hairline p-4` with a search input (icon at `left-3`, `pl-9`) and selects.

### 3.6 Stat cards (`<x-stat>`)

Label (13px `ink-400`) and an icon chip top row; big white value; optional `↑ 12%` in mint / `↓ 3%` in coral with `vs prev. 30d`; optional sparkline (`<x-chart.spark>`, gradient fill under a 1.75px line). On hover a blurred blob of the card's colour fades in at the top-right. Shown in rows: `grid gap-4 sm:grid-cols-2 lg:grid-cols-4`.

### 3.7 Modals (`<x-modal event="open-x" title="…">`)

- Opened by dispatching a window event (`$dispatch('open-domain', {license})`); the payload is available as `data`. No modal state in the page.
- Backdrop `bg-black/70 backdrop-blur-sm`; panel `rounded-3xl border-white/10 bg-ink-900 p-6`, default `max-w-lg`.
- Enters with fade + `scale-95 → 100` + 2px rise (200ms), leaves faster (150ms). Escape and backdrop click close it.
- Footer: `flex justify-end gap-2`, Cancel (`btn-ghost`) then the action.

### 3.8 Toasts

Bottom-centre, glass pill with a round mint check (or coral for `danger`), auto-dismiss after 3.2s. Triggered from anywhere:
- JS: `toast('Domain added')`, `toast('…', 'danger')`, or `window.dispatchEvent(new CustomEvent('toast', …))`.
- Server: `->with('toast', '…')` / `toast_tone`; the first validation error also becomes a danger toast.
- `$copy(text)` copies and toasts "Copied to clipboard".

### 3.9 Menus and navigation

- Dropdown: `x-data="{ o:false }"`, `@click.outside`, `x-transition.origin.top.right`, panel `glass !bg-ink-900/95 rounded-2xl p-1.5`, items reuse `.nav-link`, separators `border-t hairline my-1`.
- `.nav-link`: 13.5px, `ink-300`, `rounded-xl px-3 py-2`, icon 17px; active = `bg-white/[0.06]` + white text + volt icon.

### 3.10 Icons and charts

- `<x-icon name="key"/>`: one inline-SVG component, 24px grid, `stroke-width 1.75`, round caps, `currentColor`. ~55 names (home, grid, key, card, users, receipt, shield, download, upload, globe, refresh, copy, eye, trash, alert, check, x, arrow-right, chevron-*, sparkles, …). Add icons there; don't mix icon sets.
- Charts are hand-written SVG components (`chart.spark`, `chart.donut`), sized by CSS, colour via props. No chart library.

### 3.11 Callouts

- Notice: `rounded-2xl border border-{tone}-500/25 bg-{tone}-500/[0.06] p-4 text-sm`, leading bold word in the tone colour ("Suspended:", "Awaiting payment:").
- Secret reveal (new key): mint callout, mono key in an `ink-950` well with a copy button, and a line saying where to find it later.
- "New version" promo in the sidebar: small card with two radial glows (flare top-right, volt bottom-left), sparkles icon in amber, a text link with an arrow.

### 3.12 Avatars

Initials in `size-8 rounded-lg` with a gradient: user = volt → flare, organisation = amber → flare (dark text). No photos required.

---

## 4. Layouts

### 4.1 App shell (portal and admin share it)

```
┌ sidebar 264px (fixed) ┬ sticky header h-16 (blurred) ─────────────────┐
│ logo  [ADMIN]          │ ☰  🌐 Asia/Kolkata · Sat, Nov 22 · 14:05   $USD ▾  [+ Buy]  (NR) ▾ │
│ org chip               ├──────────────────────────────────────────────┤
│ EYEBROW group          │ EYEBROW                                         │
│  ▸ nav-link            │ Page title                       [actions]      │
│  ▸ nav-link  v4.3.0    │ subtitle in ink-400                            │
│ promo card             │                                                 │
│ ─ footer links         │ content (max-w 1400)                            │
└────────────────────────┴──────────────────────────────────────────────┘
```

- Sidebar: `bg-ink-950/95 backdrop-blur-xl border-r hairline`, grouped nav with mono eyebrows, footer links (switch area, view site, sign out).
- Below `lg` the sidebar slides in from the left (300ms) over a blurred black backdrop; a ☰ ghost button opens it.
- Header: local time zone and time (dates are stored UTC and always shown in the viewer's zone), currency switcher, the one primary action, user menu.
- Page header from layout props/slots: `eyebrow`, `title`, `subtitle`, `actions` (right-aligned, wraps under on mobile).
- A soft blurred radial glow sits behind the top of every page (volt in the portal, **flare in admin**), so the area is recognisable at a glance.

### 4.2 Page patterns

| Pattern | Structure |
|---|---|
| Overview / dashboard | Row of 3–4 stat cards → 2-column grid of cards (activity, health, shortcuts) |
| List | One card: filter bar → table → pagination footer. Primary "New …" in the header actions |
| Detail | `grid xl:grid-cols-[minmax(0,1fr)_380px]`: main column of cards (summary grid, related tables, history) + sticky-feeling aside of action forms (Edit, Status, danger zone). **Always `minmax(0,1fr)`** so wide tables scroll inside their card instead of pushing the aside off-screen |
| Summary grid | `grid sm:grid-cols-4 gap-4 text-sm`, each item = `text-xs text-ink-500` label over `text-ink-100` value |
| History / timeline | `ol` with `border-l border-white/[0.08] pl-5`; each event a 10px dot (volt normal, coral bad, ink quiet) with `ring-4 ring-ink-900`, label line, meta line "date · actor" |
| Settings | Left list of sections, right card with grouped fields and a single Save |
| Checkout | Left: steps/fields; right: sticky order summary card with live totals |
| Installer | Centred narrow card, numbered stepper on top, "Skip" as a ghost button for optional steps |

### 4.3 Marketing pages

- Hero: glass announcement pill with a gradient "New" chip → huge display headline with one serif-italic gradient word → 17–18px `ink-300` subtitle → `btn-white btn-lg` + `btn-outline btn-lg` → three mint-check reassurance points.
- Interactive proof right under the hero (the live white-label preview: pick a brand or type a name and colour, the mock app recolours).
- Sections fade up on scroll (`.reveal`), backgrounds use `.grid-bg` (56px grid masked to an ellipse) and blurred colour blobs.
- Motion accents: `animate-float` (8s), equaliser bars (`.eq-bar`), logo marquee (40s), slow spin (18s).

---

## 5. Interaction and UX rules

### 5.1 Feedback
- Every action answers within the same view: a toast for success, an inline error under the field plus a danger toast for failure.
- Buttons that call JSON endpoints use `portalApi(url, method, body)`: sends CSRF and JSON, shows the server's message as a danger toast on failure, rethrows so the UI can stay put.
- Loading: disable the button and swap its label ("Saving…") rather than showing spinners over the page.

### 5.2 Destructive and sensitive actions
- Reversible but disruptive (deactivate domain, delete release): native `confirm()` that **names the thing and the consequence**: "Deactivate music.label.com? It stops working at its next check-in."
- Irreversible (revoke license): type the identifier to confirm, button turns `btn-danger`, label says exactly what happens ("Revoke license").
- Always ask for a **reason** when an action affects a customer (suspend, revoke, refund), and show that reason back to them.
- Secrets (license keys, tokens) are masked (`LDSK-••••-…-8782`) with an eye button to reveal and a copy button; reveals are logged and the UI says so in the tooltip.

### 5.3 States
- **Empty states** explain what will appear and how to get it ("No domains yet. They appear when LabelDesk activates with this key, or add one above.").
- **Status is shown, then explained:** badge first, then a callout with dates and the next step ("Awaiting payment: works until Dec 6, 2026.").
- **Unavailable options stay visible but disabled**, with the reason ("Lifetime licenses don't renew.") instead of disappearing.
- **Show the money before the click:** prices update live as options change; the summary card shows lines, tax and total in the visitor's currency.

### 5.4 Locale
- Store UTC, display local: every date passes through one helper; the header shows the viewer's zone.
- Currency: one switcher in the header; amounts formatted by one helper (`₹45,999`, `£379`, `$1,299`), mono in tables.
- Dates: `M d, Y` in tables, `M d, Y H:i` in history, relative ("5 minutes ago") for "last seen".

### 5.5 Responsive
- Mobile first. Grids collapse to one column; header actions wrap under the title; tables scroll horizontally inside their card; sidebar becomes a drawer.
- Touch targets ≥ 36px (`btn-sm` is the smallest tappable control).
- No horizontal page scroll at any width (`overflow-x-clip` on `html` and `body`).

### 5.6 Accessibility
- Every icon-only button has `aria-label`; decorative layers are `aria-hidden` and `pointer-events-none`.
- Visible focus on every control (volt outline / ring).
- Labels are real `<label for>`; errors sit directly under their field.
- Text contrast: body `ink-200` on `ink-950`; never put `ink-500` on meaningful text, only on meta.
- `x-cloak` on anything Alpine hides, so nothing flashes on load.

---

## 6. Writing style

- Sentence case everywhere ("Renewal reminders", not "Renewal Reminders").
- Say what happened or will happen, specifically: "LIC-338WZR suspended", "Domain added as a staging domain (free)".
- Short subtitles that explain the page's job in one line.
- Name consequences in confirmations and hints ("Every install must be activated again with the new key").
- No "please", no exclamation marks, no jargon a customer wouldn't use. Use the product's nouns consistently: license, domain, install, release, order, invoice.

---

## 7. Motion

| What | Timing |
|---|---|
| Hover on buttons, links, rows | `transition-all duration-200` |
| Primary button hover | brightness 1.1 + `translateY(-1px)` |
| Dropdowns, menus | Alpine `x-transition` (~150ms), origin from the trigger |
| Modals | 200ms in (fade, scale 95→100, 2px rise), 150ms out |
| Sidebar drawer | `transition-transform duration-300` |
| Collapsible panels | `x-collapse` |
| Scroll reveal | 800ms `cubic-bezier(.2,.7,.2,1)`, 18px rise, once |
| Toasts | 250ms fade, 3.2s on screen |

Keep motion off data. Numbers and tables never animate.

---

## 8. Carrying it into LabelDesk

1. Copy `resources/css/app.css` (tokens + component layer) and `resources/js/app.js` (Alpine, `$copy`, `portalApi`, toasts, reveal).
2. Copy the Blade components: `layouts/base`, `layouts/app`, `icon`, `logo`, `modal`, `stat`, `chart/spark`, `chart/donut`, `currency-switcher` (if multi-currency).
3. Replace the nav arrays in `layouts/app.blade.php` with LabelDesk's sections (e.g. Catalog · Releases · Royalties · Payouts · Artists · Settings) and keep two areas (label admin vs. artist portal) distinguished by the background glow colour, as portal (volt) and admin (flare) are here.
4. Keep the `themeCss()` override so each LabelDesk customer's brand colour, fonts, logo and favicon re-skin the whole app from settings.
5. Reuse the page patterns in §4.2 before inventing new ones.

### Checklist for a new screen
- [ ] Uses `<x-layouts.app>` with eyebrow, title, subtitle, and at most one primary action
- [ ] Content in `.card`s; tables wrapped in `overflow-x-auto`
- [ ] Detail grids use `minmax(0,1fr)`
- [ ] Status shown with the shared badge mapping
- [ ] Empty state written as a helpful sentence
- [ ] Destructive actions confirm with the name and consequence
- [ ] Dates through the local-time helper, money through the money helper, codes in mono
- [ ] No hard-coded brand hex; icons from `<x-icon>`
- [ ] Checked at phone width and 1440px
