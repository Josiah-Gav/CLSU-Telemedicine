# Dashboard System — Shared Override Layer

> **SCOPE:** Applies to all four role dashboards: patient, nurse, physician, admin.
> **PRECEDENCE:** This file **overrides `../MASTER.md`** for dashboard routes only.
> Individual page files (`nurse-dashboard.md` etc.) override *this* file.
> Everything not restated here inherits from Master.
>
> **Phase:** 2 (design only). No application code, queries, or dependencies exist yet.
> **Depends on:** Phase 1 analytics specification (metric IDs `N-*`, `P-*`, `AD-*`, `PT-*`).

---

## 1. Why this file exists — three deliberate overrides of Master

| Master says | Dashboards use | Reason |
|---|---|---|
| **Density 3/10 — Spacious** (`--space-md: 24px`, `--space-lg: 32px`) | **Density 8/10 — Dense** (see §3) | Master's scale was tuned for the marketing `welcome` page. Operational dashboards must deliver a scan in 5–10s; 24px base padding pushes the nurse's third KPI below the fold at 768px. |
| **Page Pattern: "Portfolio Grid"** (Hero → Project Grid → About → Contact) | **Pattern: Operational Console** (see §6) | Portfolio Grid is a landing-page artifact from an auto-generated match. Dashboards are scanned, not read top-to-bottom. |
| **Card spec: `cursor: pointer` + `translateY(-2px)` on every card** | Hover lift **only on cards that are links**; static cards get no hover at all | Most KPI cards are not clickable. A hover-lift on a non-interactive element is a false affordance, and Master's own anti-pattern list forbids layout-shifting hovers. |

### Applied UI/UX Pro Max results — what was accepted and rejected

Search: `"healthcare clinical operational dashboard" --design-system --variance 3 --motion 2 --density 8`

| Returned | Verdict | Reason |
|---|---|---|
| **Style: Minimalism & Swiss Style** | ✅ **Adopted** | "Best For: Enterprise apps, **dashboards**, professional tools." Continuous with Master's existing Swiss Modernism direction. |
| **Density 8/10 dense scale** | ✅ **Adopted** | Directly matches the information-density requirement. |
| Pattern: "Hero + Testimonials + CTA" | ❌ Rejected | Landing-page conversion pattern. No dashboard has testimonials or a hero CTA. |
| Palette: cyan `#0891B2` / bg `#ECFEFF` | ❌ Rejected | Would replace a shipping, approved brand identity. `brand-green` is already in `app.css`, `tailwind.config.js`, and every existing view. |
| Typography: Atkinson Hyperlegible | ❌ Rejected (documented) | Genuinely strong for healthcare accessibility, but Master mandates one typeface system-wide and Figtree already ships. Revisit only as a deliberate, app-wide swap — never dashboards-only. |
| Motion: Scroll Reveal | ❌ Rejected | Scroll-triggered reveals delay operational data. Motion is state-change feedback only (§10). |

---

## 2. Color — semantic status system

Brand tokens are unchanged (`brand-green` `#0f6b3d`, `brand-green-deep` `#0a4d2d`, `brand-green-soft` `#edf8f0`, `brand-gold` `#d9b648`, `brand-border` `#dfe9e0`, `brand-muted` `#f4f7f4`). Brand color stays for chrome, navigation, and primary actions.

**Status colors are a separate axis from brand color.** A status must never be rendered in `brand-green` merely because green is the brand.

### Status tokens

`assigned` is **excluded from every dashboard surface** — no badge, no chart category, no legend entry. It is never written by the application workflow, so rendering it would imply a state that cannot occur.

| Status | Text/icon color | Tint background | Icon (Heroicons outline) | Emphasis |
|---|---|---|---|---|
| `pending` | `#92400e` amber-800 | `#fef3c7` amber-100 | `clock` | Medium |
| `reviewed` | `#155e75` cyan-800 | `#cffafe` cyan-100 | `clipboard-document-check` | Medium |
| `scheduled` | `#3730a3` indigo-800 | `#e0e7ff` indigo-100 | `calendar-days` | Medium |
| `active` | `#ffffff` on solid | `#0f6b3d` brand-green **solid** | `signal` | **Highest — solid fill** |
| `completed` | `#334155` slate-700 | `#f1f5f9` slate-100 | `check-circle` | **Lowest — quiet** |
| `rejected` | `#991b1b` red-800 | `#fee2e2` red-100 | `x-circle` | Medium |
| `cancelled` | `#475569` slate-600 | `#f8fafc` slate-50, dashed border | `minus-circle` | Lowest |

**Why `active` is solid and `completed` is quiet.** This deliberately inverts the usual "green = success" convention. In an operational console, *active* is the state demanding attention and *completed* is the resting state. Encoding urgency through emphasis rather than hue also solves a real contrast problem: `active` and `completed` would otherwise both be green and indistinguishable at a glance.

### Priority tokens

| Priority | Treatment |
|---|---|
| `High` | `#991b1b` red-800 on `#fee2e2` red-100, `arrow-up-circle` icon, **plus a 3px left border** on the containing row/card |
| `Normal` | `#475569` slate-600 on transparent, no icon, text label only |

Priority never appears as color alone. The triple encoding is **color + icon + text**, per UX guideline *Accessibility / Color Only* (severity High): "Don't convey information by color alone. Use icons/text in addition to color."

### Chart palette

Charts reuse **the exact same status colors** as badges. A `completed` bar and a `completed` badge are the same slate; a `rejected` bar and badge are the same red. This is the single highest-value consistency decision in the system — it removes any need to learn a second color language.

| Use | Colors |
|---|---|
| Single-series (volume, symptom frequency) | All bars/line `#0f6b3d` brand-green |
| Two-series split (initial vs follow-up) | Initial `#0f6b3d` brand-green · Follow-up `#1d7fa8` teal — both dark enough for 4.5:1 label text, distinguishable under deuteranopia and protanopia |
| Status distribution | The status tokens above, one per bar |
| Priority | High `#991b1b` · Normal `#94a3b8` slate-400 |
| Severity ramp | Sev 1 `#cbd5e1` · Sev 2 `#fcd34d` · Sev 3 `#f59e0b` **+ diagonal hatch** · Sev 4 `#b91c1c` |

**Severity 3 carries a diagonal hatch pattern.** Severity defaults to 3 the instant a symptom is selected, so bucket 3 mixes deliberate answers with silent non-responses. The hatch marks it as "contains defaults" visually, doubles as a non-color encoding, and makes the caveat legible without reading the footnote. This is required, not decorative.

### Contrast

Every foreground/background pair above meets **4.5:1**. Verify with a checker before shipping; do not substitute a lighter tint to "soften" a badge.

---

## 3. Spacing — dense scale (overrides Master)

| Token | Value | Usage |
|---|---|---|
| `--space-xs` | `4px` | Icon-to-label gap, badge padding-y |
| `--space-sm` | `8px` | Chip gaps, tight stacks |
| `--space-md` | `12px` | Card internal padding (mobile), table cell padding-y |
| `--space-lg` | `16px` | Card internal padding (desktop), grid gap |
| `--space-xl` | `24px` | Section padding, gap between card grid and chart |
| `--space-2xl` | `32px` | Gap between major dashboard sections |

Tailwind mapping: `p-3` / `p-4` cards, `gap-4` grids, `space-y-6` between sections, `space-y-8` between major bands. **No `p-6`/`p-8` on KPI cards** — that is Master's spacious scale and it costs a card per viewport.

Page container: `max-w-7xl mx-auto px-4 sm:px-6 lg:px-8` — unchanged from the existing dashboards, so analytics drop into the current shell without reflowing the page.

---

## 4. Typography

Figtree throughout (already loaded). One typeface, per Master.

| Role | Size / weight | Notes |
|---|---|---|
| KPI value | `text-3xl` 30px / 600 | **`font-variant-numeric: tabular-nums` required** so KPI rows don't jitter between renders |
| KPI label | `text-xs` 12px / 500, uppercase, `tracking-wide`, slate-600 | slate-600 on white = 7.5:1 ✓ |
| KPI supporting line | `text-xs` 12px / 400, slate-500 | Denominators, basis labels |
| Page title | `text-xl` 20px / 600 | |
| Section title | `text-base` 16px / 600 | |
| Body / table cell | `text-sm` 14px / 400 | Staff dashboards |
| Caption / meta | `text-xs` 12px / 400 | Never smaller than 12px |

**Base size differs by role — this is deliberate.**
- **Staff dashboards (nurse, physician, admin): 14px base.** A dense professional tool used many times daily by trained users.
- **Patient dashboard: 16px base.** The general public, including older and less confident users, on a health service they may be anxious about. Density is not a virtue here.

---

## 5. Borders, radius, shadows

| Property | Value | Note |
|---|---|---|
| Border | `1px solid` `brand-border` `#dfe9e0` | Every card and section. Replaces `gray-200`. |
| Radius — cards, charts, tables | `rounded-xl` 12px | Reduced from the existing `rounded-3xl` (24px). 24px on a dense 4-up KPI grid reads as "card soup"; 12px holds structure. |
| Radius — badges, chips | `rounded-full` | |
| Radius — buttons, inputs | `rounded-lg` 8px | |
| Shadow — resting cards | **none** — border only | Flat + bordered is the Swiss/Minimalism direction and keeps a 12-card admin view calm |
| Shadow — raised (dropdown, popover, modal) | `shadow-lg` | |
| Shadow — hover on *linked* cards only | `shadow-sm` + `border-brand-green/40` | **No transform, no translateY** — Master's anti-pattern list forbids layout-shifting hovers |

The existing dashboards use `rounded-3xl` on their banner and notification panel. **Keep `rounded-3xl` on the existing banner** for visual continuity; use `rounded-xl` for all new analytics surfaces. The size difference reads as hierarchy (chrome vs. data), not inconsistency.

---

## 6. Layout pattern — "Operational Console"

Replaces Master's "Portfolio Grid". Five bands, top to bottom, in fixed order:

```
┌─ BAND 1 · Page header ─────────────────────────────┐
│  Title + role context + (page-level actions)       │
├─ BAND 2 · NOW — operational, never date-filtered ──┤   ← above the fold
│  Queue counts / current workload / what's next     │
├─ BAND 3 · Analytics controls ──────────────────────┤   ← the filter boundary
│  Date filter + scope statement                     │
├─ BAND 4 · Historical analytics — date-filtered ────┤
│  Primary trend chart, then secondary distributions │
├─ BAND 5 · Supporting ──────────────────────────────┤
│  Tables, notifications panel                       │
└────────────────────────────────────────────────────┘
```

**Band 3 is a visible boundary, not a control floating at page top.** This is the load-bearing idea of the whole system: everything above it is *now*, everything below it is *the selected period*. The boundary renders as a full-width horizontal rule with the filter sitting on it, so the split is structural rather than something the user has to infer.

Rationale: the nurse's shared pending queue must never disappear because someone selected "This Week". Placing the filter *below* the operational band makes its scope self-evident instead of surprising.

---

## 7. Reusable components

All are **anonymous Blade components** in `resources/views/components/`, auto-registered as `x-*`, declaring `@props`. Per Laravel stack guideline *Blade Templates / Use Blade components for reusable UI* (severity **High**): "Do: Use `x-*` components with `@props` for all reusable UI. Don't: duplicate HTML blocks / `@include` for anything reusable."

This matches the project's existing convention (`components/button-primary.blade.php`, `components/modal.blade.php`, …). **Nine components — no more.** Every one below appears on at least two dashboards; anything used once stays inline markup.

### 7.1 `<x-dash.stat>` — KPI / stat card

- **Purpose:** One number plus its meaning. The atom of every dashboard's Band 2 and Band 4.
- **Props:** `label`, `value`, `supporting` (denominator/basis text), `icon`, `tone` (`neutral|critical|active`), `href` (optional), `busy` (bool).
- **Structure:** label (xs uppercase) → value (3xl tabular) → supporting (xs slate-500). Optional 20px icon top-right, `aria-hidden="true"`. Optional chip row slot beneath for breakdowns.
- **States:**
  - *Default* — white, `brand-border`.
  - *Critical* — `tone="critical"`: red-100 tint, red-800 value, `arrow-up-circle` icon, **3px left border**. Used only when a number represents work that is overdue or high-priority.
  - *Linked* — when `href` present: whole card is one `<a>`, `cursor-pointer`, hover adds `shadow-sm` + green border. Never a nested link inside a card.
  - *Loading* — skeleton bar in place of the value; `aria-busy="true"`.
  - *Empty/zero* — renders `0`, never blank. Zero is information.
  - *Unavailable* — renders `—` when a rate has a zero denominator. **Never `0%`, never `NaN`.**
- **Responsive:** value drops `text-3xl`→`text-2xl` below 375px. Card is full-width at 375, 2-up at 768, 4-up at 1024+.
- **Accessibility:** the whole card is one focusable link when `href` is set (not the number alone). Live-updating counts use `role="status" aria-atomic="true"` wrapping **meaningful text** — `"4 unclaimed requests"`, never a bare `4`. Per UX guideline *Accessibility / Contextual Live Badge Updates* (severity High): "Don't announce a bare number."

### 7.2 `<x-dash.section>` — dashboard section

- **Purpose:** Titled band wrapper. Provides the consistent heading + optional description + optional right-aligned action.
- **Props:** `title`, `description`, `level` (h2/h3), `id`.
- **Structure:** heading row (title left, action slot right) → optional description (`text-sm slate-600`, max ~68ch) → content slot.
- **Accessibility:** renders a real `<section aria-labelledby>` with a real heading. Headings descend without skipping: page `h1` → section `h2` → card title `h3`.
- **Responsive:** heading row stacks below 640px (action moves under title, full width).

### 7.3 `<x-dash.chart>` — chart container

- **Purpose:** The **only** way a chart is placed on any dashboard. Wraps header, canvas, empty state, and the accessible data table so none of them can be forgotten.
- **Props:** `title`, `description`, `series` (array), `type`, `chartId`, `summary` (one-sentence text takeaway), `footnote`.
- **Structure:**
  1. Header — title, optional info tooltip trigger, optional footnote marker.
  2. Fixed-height wrapper (`h-64` desktop / `h-56` mobile) containing `<canvas>`. **Height must be explicit** — Chart.js with `maintainAspectRatio: false` collapses to 0 inside an auto-height parent.
  3. `data-chart` attribute holding `@json($series)`.
  4. `<details>` disclosure: "View data as table" → real `<table>` with the same numbers.
  5. Footnote slot (`text-xs slate-500`).
- **States:** *loaded* · *empty* (see 7.6, replaces canvas entirely) · *sparse* (fewer than 4 points on a trend → render stat line instead of a line chart, per chart-domain rule) · *loading* (skeleton block, `aria-busy`) · *error* (see 7.8).
- **Accessibility:** `<canvas role="img" aria-label="{{ $summary }}">`. The data table is the real fallback and ships every time. Animation disabled under `prefers-reduced-motion`.

### 7.4 `<x-dash.filter-bar>` — date filter

- **Purpose:** Band 3. The visible boundary between *now* and *the selected period*.
- **Props:** `current`, `presets`, `action` (route), `scopeNote`.
- **Structure:** full-width rule; on it, a label ("Showing"), the control, and a scope sentence (e.g. *"Historical analytics only — the queue above always shows current state."*).
- **Control choice:** a **native `<select>`** with a submit-on-change form, plus a `<noscript>` submit button. Not a custom Alpine dropdown.
  - *Rationale:* native selects get free keyboard support, free screen-reader semantics, free mobile OS pickers, and correct touch targets. A custom dropdown would need all of that rebuilt. Presets are 6 fixed options — there is no requirement a native control cannot meet.
- **Custom range:** selecting "Custom range" reveals two native `<input type="date">` fields plus an Apply button (Alpine `x-show`). Server rejects end-before-start; the error renders next to the fields, not at page top.
- **State:** current selection persists in the query string (`?range=last_30_days`), so the view is bookmarkable and back-button-correct.
- **Responsive:** inline row at ≥640px; stacked full-width control at 375px (min touch target 44px).

### 7.5 `<x-dash.badge>` — status & priority badge

- **Purpose:** One component, two variants. Used in tables, cards, and chart legends.
- **Props:** `status` **or** `priority`, `size` (`sm|md`).
- **Structure:** pill — 14px icon + text label. **Both always present.** There is no icon-only mode and no color-only mode.
- **Rejects `assigned`** — passing it renders nothing and is treated as a programming error, so the dead status cannot leak into the UI through a data path.
- **Accessibility:** icon `aria-hidden="true"`; the text label is the accessible name. Meets 4.5:1 in every variant.
- **Responsive:** `size="sm"` (12px text) inside dense tables; `md` elsewhere. Never truncates — the label is the meaning.

### 7.6 `<x-dash.empty>` — empty state

- **Purpose:** Every chart, table, and queue needs one. Prevents a blank canvas or "No data".
- **Props:** `title`, `message`, `action` (optional), `tone` (`neutral|positive`).
- **Two tones, and the distinction matters:**
  - *positive* — an empty nurse queue is a **success**: "The queue is clear." Green tint, check icon.
  - *neutral* — no data in the selected period: dashed border, muted, plus a widening action ("Try last 90 days").
- **Copy rule:** never the string "No data". Every empty state names what is empty and what to do next.
- **Accessibility:** real text in the DOM, not a background image. Icon `aria-hidden`.

### 7.7 `<x-dash.table>` — responsive data table

- **Purpose:** Upcoming consultations, symptom-standardization candidates.
- **Structure:** `<table>` inside an `overflow-x-auto` wrapper, per UX guideline *Responsive / Table Handling*.
- **Responsive:** below 768px the table switches to a **stacked card list** (each row becomes a bordered block with `label: value` pairs), *not* a horizontally scrolled table. Horizontal scroll is the fallback for tables too wide to restructure; the card list is preferred where rows are few and short. Each page file states which it uses.
- **Accessibility:** `<caption>` (visually hidden if the section heading already names it), `<th scope="col">`, `aria-sort` on sortable headers. The scroll container gets `tabindex="0"` and an accessible name so keyboard users can scroll it.

### 7.8 `<x-dash.state>` — loading & error

- **Purpose:** One component covering the two non-happy paths, since their layout is identical.
- **Props:** `variant` (`loading|error`), `message`, `retry` (route).
- **Loading:** skeleton blocks matching the final layout's dimensions — **reserve the exact height** to keep CLS < 0.1 (UX priority 3). Never a centered spinner that collapses the card.
- **Error:** amber/red bordered block, plain-language message ("Analytics could not be loaded."), a Retry link, and — critically — **it never replaces the whole dashboard**. A failed chart shows an error in its own container while every other panel still renders.
- **Accessibility:** loading sets `aria-busy="true"` on the container; error uses `role="alert"`.

### 7.9 Notification panel — integration, not a new component

The existing `dashboardNotifications()` Alpine panel already ships on all three staff dashboards and **is not rewritten in this phase**. It moves to **Band 5** on every dashboard.

*Rationale:* it is a log of things that already happened — reference material, not a decision surface. It currently occupies the most valuable screen real estate on three dashboards. Moving it below analytics is the single largest information-hierarchy improvement available, and it requires only relocating the existing markup.

---

## 8. Chart patterns

**No chart library is installed. Do not install one in this phase.** Phase 1 selected Chart.js v4 via npm/Vite; these specs are library-agnostic and hold regardless.

### Global chart rules

1. Every chart ships inside `<x-dash.chart>` — never a bare `<canvas>`.
2. Every chart has a **visible data table** in a `<details>` disclosure.
3. **Never distinguish series by hue alone.** Multi-series charts add distinct line styles (solid/dashed) or direct labels.
4. Every chart has an explicit height on its wrapper.
5. Legends: **omitted for single-series** (the title says it). Two-series legends render **below** the chart, wrapping — never right-aligned (breaks below 768px).
6. Tooltips: on hover **and on keyboard focus**. Never the only route to a value — the data table always carries it.
7. Animation ≤ 300ms, disabled entirely under `prefers-reduced-motion`.
8. Sorting: **descending by value** where ranking is the insight (symptoms); **fixed lifecycle order** where shape-comparability across periods is the insight (status). This intentionally overrides the chart-domain default of "always sort descending" for status only, so week-to-week comparison stays possible.

### Chart type decisions

| Chart | Type | Why this type |
|---|---|---|
| Request volume over time | **Line** | Chart domain, *Trend Over Time*: time axis + rate-of-change is the insight. **Hard rule: fewer than 4 points → do not render a line.** Fall back to a stat line ("3 requests this period"). This is a real case for "Today" on a low-volume infirmary. |
| Volume split initial vs follow-up | **Stacked bar** (time axis) | Two series over time; stacked bar reads totals *and* split without the crossing-lines problem. |
| Status distribution | **Horizontal bar** | Chart domain, *Part-to-Whole*: donut is invalid at ">5 categories" **and** in an "accessibility-first context". Status has 7 real categories. Horizontal bar also fits long labels. |
| Priority distribution | **100% stacked bar** (single row) | Only 2 categories. A donut for a binary split is decoration; a single proportion bar is read instantly and costs one row of height. |
| Initial vs follow-up (totals) | **100% stacked bar** (single row) | Same reasoning. |
| Symptom frequency | **Horizontal bar, top 10, descending** | Chart domain, *Compare Categories*: ranking is the insight, categories ≤ 15, and horizontal accommodates long names like "Shortness of Breath". |
| Severity distribution | **Vertical bar, 4 fixed columns** | Ordinal scale with a natural order; fixed columns keep position stable across periods. Severity 3 hatched (§2). |

### Explicitly forbidden

- **No concern-category chart.** `concern_category` is always `general`; a chart would imply meaningful categories that do not exist.
- **No "average severity" metric anywhere.** Severity 3 is a default, so a mean is not interpretable.
- **No `assigned` category** in any status visualization.
- **No gauges or speedometers** for completion rate — a gauge implies a target nobody has set.
- **No sparklines inside KPI cards** in v1 — they add a chart-rendering dependency to Band 2, which must render instantly.
- **No staff rankings or leaderboards** on any dashboard (§11).

---

## 9. Responsive specification

Breakpoints follow Tailwind defaults: `sm:640` `md:768` `lg:1024` `xl:1280`.

| Element | 375px | 768px | 1024px | 1440px |
|---|---|---|---|---|
| **KPI cards** | 1-up, full width | 2-up | 4-up | 4-up (container caps at `max-w-7xl` 1280px) |
| **Primary chart** | Full width, `h-56` | Full width, `h-64` | Full width, `h-64` | Full width, `h-72` |
| **Secondary charts** | Stacked, full width | Stacked, full width | 2-up | 2-up (admin: 3-up) |
| **Filter bar** | Stacked, full-width native `<select>`, 44px min height | Inline row | Inline row | Inline row |
| **Tables** | Stacked card list | Card list or `overflow-x-auto` | Full table | Full table |
| **Navigation** | Existing bottom nav (unchanged) | Existing sidebar | Sidebar expanded | Sidebar expanded |
| **Notification panel** | 1 column | 2 columns | 2 columns | 2 columns |
| **Section gaps** | `space-y-6` | `space-y-6` | `space-y-8` | `space-y-8` |

### Named hard cases

- **Long symptom names** ("Shortness of Breath", "Nausea / Vomiting"): horizontal bar labels sit left of the bar at ≥768px, truncated at ~24 chars with a `title` attribute. At 375px the label moves **above** its bar (full text, no truncation) — vertical space is cheaper than horizontal on mobile. Full names always appear untruncated in the data table.
- **Chart legends:** wrap below the chart; never a fixed-width right column.
- **KPI supporting text** (e.g. "34 of 50 concluded") never truncates — it wraps to a second line. The denominator is the point.
- **No horizontal page scroll at any width.** Only designated `overflow-x-auto` containers scroll.
- **Touch targets** ≥ 44×44px with ≥8px spacing on all filter controls, links, and table row actions.

---

## 10. Motion

Motion is **feedback only** — it confirms a state change. It never introduces, reveals, or decorates.

| Event | Treatment |
|---|---|
| Card/link hover | 150ms border + shadow. **No transform.** |
| Filter change | Full page load (no transition needed) |
| Chart first paint | ≤300ms ease-out, once |
| Live count change | Value cross-fades 150ms; no bounce, no count-up animation |
| `active` status dot | Slow 2s pulse — **the only ambient motion in the system**, and it is earned: it marks a live consultation |
| **All of the above** | Fully disabled under `prefers-reduced-motion: reduce` — including the pulse, which becomes a static dot |

No scroll-triggered reveals. No page-transition animations. No skeleton shimmer (a static skeleton is calmer and cheaper).

---

## 11. Accessibility specification

Baseline: **WCAG 2.1 AA.**

### Color & contrast
- All text ≥ **4.5:1**; large text (≥24px) ≥ 3:1; UI borders and chart bars ≥ **3:1** against their background.
- **Nothing is encoded by color alone.** Status = color + icon + text. Priority = color + icon + text + left border. Severity = color + position + hatch on the default bucket.

### Keyboard
- Every interactive element reachable in a logical DOM order; no positive `tabindex`.
- **Visible focus on everything**: `focus-visible:ring-2 ring-brand-green ring-offset-2`. Focus rings are never removed — a Master-level anti-pattern.
- Focus must not be obscured by any sticky header.
- `overflow-x-auto` scroll containers get `tabindex="0"` + accessible name so they can be scrolled by keyboard.
- Chart tooltips reachable by focus, not hover alone.

### Screen readers
- One `<h1>` per dashboard; headings descend without skipping.
- Landmarks: `<main>`, `<nav>`, `<section aria-labelledby>`.
- **Charts:** `<canvas role="img" aria-label="{one-sentence takeaway}">` plus a real `<table>` in a `<details>` — the table is the primary accessible representation, not a courtesy.
- **Live counts:** `role="status" aria-atomic="true"` around meaningful text ("4 unclaimed requests"), never a bare number, and never one live region per badge.
- Loading: `aria-busy="true"`. Errors: `role="alert"`.
- Icons decorative-by-default: `aria-hidden="true"` with the adjacent text carrying meaning.

### Forms (filter bar)
- Visible `<label>` on the range select — not placeholder-only.
- Custom-range validation errors render **beside the fields**, not only in a page-top summary.
- Native `<select>` and `<input type="date">` retain OS-level assistive behavior.

### Typography
- Body ≥14px staff / ≥16px patient. Never below 12px anywhere.
- Line-height ≥1.5 for body copy. Measure ≤68ch for descriptive text.
- Zoom to 200% without loss of content or horizontal scroll.

---

## 12. Privacy rules (binding on all dashboards)

1. **No staff rankings, leaderboards, or per-person performance comparison** on any dashboard. At a university infirmary's team size a per-staff chart is de-anonymizing by construction, and ranking clinicians by volume rewards the wrong behavior. If workload balancing is ever needed, use aggregate capacity — never named individuals.
2. **No patient-identifiable data in aggregate analytics.** The only place a patient is named is the physician's own upcoming-consultations table, where the clinical relationship already authorizes it.
3. **Custom symptom free text** — least-identifying useful representation, per surface:
   - **Nurse: count only.** No text. The live queue is small enough that free text is effectively attributable to one waiting patient.
   - **Physician: not shown at all.** They read their own patient's symptoms in the consultation itself.
   - **Admin: normalized, aggregated, and gated at n ≥ 3.** Terms below the threshold collapse into one "Low frequency" row showing a count and no text. This is k-anonymity at k=3: a term appearing three or more times cannot be traced to one person, and a single unique entry — the case most likely to be sensitive — is structurally unreachable through the UI.
4. The n ≥ 3 threshold is enforced in the **service layer**, not in Blade. A privacy control living in a template is one `@if` away from being lost.
5. Symptom analytics exclude follow-ups (`type = 'follow_up'`), which copy the parent's symptoms verbatim. Any symptom section carrying explanatory text must say so.

---

## 13. Pre-delivery checklist (dashboard-specific)

Extends Master's checklist.

- [ ] No Bootstrap classes anywhere
- [ ] Every chart inside `<x-dash.chart>` with its `<details>` data table
- [ ] Every percentage displays its denominator
- [ ] Zero-denominator rates render `—`, never `0%`/`NaN`
- [ ] No `assigned` status rendered anywhere
- [ ] No concern-category chart
- [ ] No average-severity metric
- [ ] Severity 3 bar carries the hatch pattern + footnote
- [ ] Nurse queue renders identically regardless of selected date range
- [ ] Filter bar sits **below** the operational band on every dashboard
- [ ] KPI numbers use `tabular-nums`
- [ ] Live counts announce meaningful text, not bare numbers
- [ ] Status/priority never encoded by color alone
- [ ] Focus visible on every interactive element
- [ ] No horizontal page scroll at 375px
- [ ] Touch targets ≥44px
- [ ] `prefers-reduced-motion` disables all motion including the active-pulse
- [ ] No staff rankings; custom symptom text gated per §12
