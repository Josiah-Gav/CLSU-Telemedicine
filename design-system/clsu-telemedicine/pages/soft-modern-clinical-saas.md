# Soft Modern Clinical SaaS — Design Direction (Phase 2)

> **Authority order for this app's UI, most specific wins:** this file →
> `dashboards-shared.md` / the per-dashboard page files → `../MASTER.md`.
> Where this file and `MASTER.md`'s "Approved Color Palette" agree, either
> is fine to cite. Where this file and `MASTER.md`'s **Component Specs**
> section disagree (they do — see "Known conflicts" below), this file wins.
> `MASTER.md` itself is not rewritten here; regenerating it needs `--force`,
> which needs explicit user authorization (see `IMPLEMENTATION-HANDOFF.md`).

**Locked direction:** Soft Modern Clinical SaaS. Every token below is taken
from what the app already ships (`resources/css/app.css`, `tailwind.config.js`,
the dashboard components in `resources/views/components/dash/`) — this file
documents the direction the app has already been converging on and gives
later phases one place to build from consistently, rather than inventing a
new look.

## What "Soft Modern Clinical SaaS" means here

- **Soft** describes the *edges* (moderate radius, restrained shadow), never
  the *contrast*. Text and status colors still meet 4.5:1 minimum contrast.
- **Modern clinical SaaS**, not a marketing site or a portfolio: information
  density is driven by what a nurse/physician/admin needs to scan quickly,
  not by visual rhythm for its own sake.
- **Calm**: one accent color family (brand green) does the CTA/active-state
  work; status meaning is carried separately by semantic colors (§ Status
  Semantics), never by the brand accent.

### Explicitly rejected

- **Full Neumorphism** — no inset/soft-embossed shadows anywhere in this
  app; every existing card is a flat fill with a 1px border, which is what
  "soft" refers to here, not extruded plastic surfaces.
- **Glassmorphism** — no backdrop-blur-on-content, no translucent panels
  over body copy. (The sidebar's `backdrop-blur-sm` on `layouts/navigation.blade.php`
  is the one existing exception — a fixed chrome element, not content — and
  is left as-is rather than treated as a pattern to extend.)
- **Excessive gradients** — the one gradient in the app (the welcome-banner
  `from-brand-green-soft via-white to-brand-gold-soft`) is a deliberate,
  singular "hero" treatment repeated identically across all four
  dashboards. It is not a general pattern to reach for elsewhere.
- **Dribbblized UI** — no decorative illustration, no motion that doesn't
  communicate a state change, no styling choice made because it would
  screenshot well.

## Typography

One typeface system-wide: **Figtree** (`font-sans`), loaded via bunny.net in
`layouts/app.blade.php`/`layouts/guest.blade.php`. Do not introduce a second
typeface. Scale, as already used:

| Role | Classes | Example |
|---|---|---|
| Page title (in-banner) | `text-2xl font-bold text-slate-900` | "Hello Nurse Maria" |
| Section title | `text-lg font-bold text-slate-900` | "Shared Queue", "Right Now" |
| Card title | `text-xl font-bold text-slate-900` | Consultation card heading |
| Body | `text-sm text-slate-600` / `text-slate-700` | Descriptions, sentences |
| Secondary / meta | `text-xs text-slate-400` / `text-slate-500` | Timestamps, eyebrows |
| Eyebrow / label | `text-xs font-semibold uppercase tracking-wide text-slate-400` | "Submitted", "Status" |
| Data value | `text-2xl md:text-3xl font-bold tabular-nums` | `<x-dash.stat>` figures |

`tabular-nums` on every place digits line up in a column (stat tiles,
severity counts) — already the convention in `<x-dash.stat>` and the admin
symptom cards.

## Spacing

4/8-based rhythm, exactly as already used — no new scale introduced:

`gap-2` (8px) · `p-4`/`gap-4` (16px) · `p-6` (24px) · `mt-6` (24px, the
standard vertical gap between dashboard bands) · `p-8` (32px, banner
padding on `sm:`) · `space-y-8` (32px, the outer page rhythm in
`max-w-7xl mx-auto space-y-8`).

## Color

Authoritative source: `tailwind.config.js` `theme.extend.colors.brand`,
mirrored in `resources/css/app.css` `:root`. Reproduced here so this file is
self-contained:

| Token | Hex | Use |
|---|---|---|
| `brand-green` | `#0f6b3d` | Primary accent — CTAs, links, active state, key icons |
| `brand-green-deep` | `#0a4d2d` | Hover/pressed state, high-contrast text on tinted surfaces |
| `brand-green-soft` | `#edf8f0` | Tinted section backgrounds, badges |
| `brand-gold` | `#d9b648` | Sparing secondary accent — never a button's only color cue |
| `brand-gold-soft` | `#fff7dc` | Tinted info panels |
| `brand-border` | `#dfe9e0` | Card/section borders (replaces `gray-200` in new work) |
| `brand-muted` | `#f4f7f4` | Neutral warm section background |

Neutrals, text and semantic colors are stock Tailwind (`slate-*`, `gray-*`,
`emerald-*`, `amber-*`, `red-*`) — no separate neutral scale is introduced.

### Status semantics (already centralized — extend here, don't fork)

`App\Support\StatusBadge` is the single source of truth for status/priority/
severity → label + color + icon, consumed by `<x-dash.badge>` and the
physician inbox's serialized JSON. **Extend this class, never duplicate its
maps elsewhere**, when a new status needs a color. As of Phase 2 it also
carries `patientMeaning()` — the plain-language sentence a patient sees
alongside their status badge (see `PatientStatusMeaningDisplayTest.php`).

**Update (Phase 4):** `patient/dashboard.blade.php` and
`patient/consultation-details.blade.php` no longer each hand-compute their
own status→color mapping — both now call `StatusBadge::patientClasses()`.
The two inline copies had actually drifted apart (only one of them gave
`pending`/`assigned` their own yellow treatment; the other silently fell
through to the generic default), so centralizing fixed a real bug as a
side effect of removing the duplication. The palette itself is unchanged
and deliberately **not** merged into `STATUS_MAP` — it stays its own,
lighter/pastel set suited to a patient-facing content card rather than a
dense staff table row; forcing the two palettes to match is a separate,
larger visual change, still deferred.

Severity is **1–4** (Very Mild / Mild / Moderate / Severe) — verified against
the shipping implementation in Phase 1.1. Never introduce a fifth value or
relabel these four.

## Cards

- Radius: `rounded-3xl` reserved for the top-of-page hero/welcome banner and
  a small number of large patient-facing content cards; `rounded-2xl` for
  dashboard section cards; `rounded-xl` (12px) for analytics/chart surfaces
  per `dashboards-shared.md` rule 18.
- Border: `border border-brand-border` (or `border-gray-200` on
  not-yet-migrated patient pages) on every card — this is what "soft" means
  structurally.
- Shadow: analytics/dashboard-band cards carry **no shadow** — border only
  (`dashboards-shared.md` rule 19). A small number of patient-facing content
  cards (consultation card, follow-up card) carry `shadow-sm` with a hover
  lift (`hover:shadow-lg`) because they are links — consistent with rule 20
  ("hover lift only on cards that are actually links"). Don't add a shadow
  to a card that isn't clickable.
- No `cursor: pointer` or `translateY` hover effect on a non-interactive
  card (this is where `../MASTER.md`'s generic `.card` spec conflicts with
  the app's own anti-pattern list — see Known conflicts below).

## Buttons

**Update (Phase 4): componentized.** Four real Blade components now exist —
`resources/views/components/button-{primary,secondary,danger,ghost}.blade.php`
— extracted from the inline on-brand pattern that was already the app's
real design system (see the Phase 2 analysis this section used to carry,
preserved below for context). Each renders as `<a>` when given an `href`
prop, `<button>` otherwise; supports `size="sm"` (compact, no forced
touch-target minimum, for a dense desktop table row) alongside the
`size="md"` default (≥44px touch target); and documents, in its own
docblock, the one sharp edge worth knowing before using it — Blade's bare
`:attr="…"` shorthand collides with Alpine's identical-looking shorthand
on a `<x-component>` tag (Blade wins, evaluates the value as PHP); use
Alpine's unabbreviated `x-bind:attr="…"` on these components instead.

Migrated so far (Phase 4 pilot, one page per role, each verified live):
`admin/users/index.blade.php`, `nurse/follow_up_requests.blade.php`,
`physician/follow_up_request.blade.php`, and the patient dashboard's
primary CTA + follow-up cancel button. Not yet migrated: everywhere else
still using the inline pattern directly — safe to convert incrementally,
same visual output, following the pilot pages as the reference.

The **Breeze-scaffolded components** (`x-primary-button`,
`x-secondary-button`, `x-danger-button`) have been fully migrated off
(Phase 6): all seven auth pages (login, register, forgot/reset password,
confirm password, verify email, staff activation) now use the
`<x-button-*>` set above. A repo-wide search found zero remaining
references to any of the three Breeze components anywhere — views, tests,
or PHP. `x-primary-button` had exactly seven call sites, all on these auth
pages; `x-secondary-button` and `x-danger-button` had none even before this
phase. Their component files (`components/primary-button.blade.php`,
`secondary-button.blade.php`, `danger-button.blade.php`) were deleted in a
follow-up pass once that zero-reference finding was re-confirmed.

| Variant | Component | Base classes |
|---|---|---|
| Primary | `<x-button-primary>` | `rounded-lg bg-brand-green ... text-white hover:bg-brand-green-deep` |
| Secondary | `<x-button-secondary>` | `rounded-lg border border-brand-border bg-white text-slate-700 hover:bg-brand-green-soft hover:text-brand-green-deep` |
| Destructive | `<x-button-danger>` | `rounded-lg bg-red-600 text-white hover:bg-red-700` |
| Ghost/Tertiary | `<x-button-ghost>` | text-only, `text-brand-green hover:underline` |
| Disabled | all four | `disabled:opacity-50 disabled:cursor-not-allowed`, built in |
| Loading | — | still no standard spinner pattern; SweetAlert2's own loading state covers async dialogs. A native in-button spinner remains open (see Remaining Work) |

Radius is `rounded-lg`, not the `rounded-full` pill some inline instances
used — this phase's own brief called out "no excessive pill styling",
and `rounded-lg` was already the majority inline pattern (15 files vs. 12
using `rounded-full`), so this is a minor, deliberate convergence, not an
invented new radius.

## Focus states & accessibility

- Never remove a focus ring. Interactive elements without an explicit
  `focus:` class still get the browser default outline — acceptable, but
  `focus:ring-2 focus:ring-brand-green/40 focus:outline-none` is preferred
  for new work to keep the ring on-brand.
- Status is never color-only: `<x-dash.badge>`/`StatusBadge` pair every
  color with a label and (on dashboards) an icon.
- Touch targets ≥44px on primary actions (≥48px on the patient's single
  primary action, e.g. "Request a Consultation").
- `prefers-reduced-motion` disables the one existing ambient animation (the
  active-status pulse) — already implemented, don't regress it.

## Known conflicts in `../MASTER.md` (not fixed here)

`IMPLEMENTATION-HANDOFF.md` already flags that `MASTER.md`'s **Component
Specs** section (the raw `.btn-primary`/`.card`/`.input`/`.modal` CSS blocks)
still contains the superseded navy palette (`#1E3A5F`, `#A16207`, `#F8FAFC`)
and gives cards `cursor: pointer` + `translateY(-2px)` on hover
unconditionally — contradicting that same file's own anti-pattern list
("no layout-shifting hovers") one page later. This file's Buttons/Cards
sections above are what Phase 2 and onward should actually follow;
`MASTER.md`'s Component Specs are left untouched pending an authorized
`--force` regeneration, exactly as `IMPLEMENTATION-HANDOFF.md` already
recorded.
