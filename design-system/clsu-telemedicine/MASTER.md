# Design System Master File

> **LOGIC:** When building a specific page, first check `design-system/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.

> **AUTHORITY NOTE (Phase 2/4, added without regenerating this file):**
> The current locked direction for this project is **Soft Modern Clinical
> SaaS**, documented in full at
> `design-system/clsu-telemedicine/pages/soft-modern-clinical-saas.md`. That
> file's Buttons/Cards/Typography/Spacing/Color sections are authoritative
> for all new work — read it before this file. This Master file is kept
> as-is (not regenerated) because it contains real, known conflicts with
> the current direction — see the flag directly above **Component Specs**
> below — and regenerating it needs an explicit `--force` the user has not
> given. Don't copy CSS from the Component Specs section below without
> checking it against the authoritative file first.

---

**Project:** CLSU Telemedicine
**Generated:** 2026-08-24 14:52:26
**Revised:** 2026-08-24 — palette replaced with the app's real, already-shipping brand
tokens after the auto-generated match below proved a mismatch (see note).
**Category:** University Telemedicine / Infirmary Portal
**Design Dials:** Variance 4/10 (Balanced / Modern) | Motion 3/10 (Subtle) | Density 3/10 (Spacious)

---

## ⚠️ Superseded auto-generated match (kept for reference only)

The searches below (query: "university infirmary telemedicine…") matched a
**Research Lab / University Department** category — navy/gold, EB
Garamond/Crimson Text serif — which does not reflect this app. The tokens
that follow in this file are what's actually approved and in use; do not
apply the navy/serif values below to any page.

### Color Palette (superseded — do not use)

| Role | Hex | CSS Variable |
|------|-----|--------------|
| Primary | `#1E3A5F` | `--color-primary` |
| On Primary | `#FFFFFF` | `--color-on-primary` |
| Secondary | `#2563EB` | `--color-secondary` |
| On Secondary | `#FFFFFF` | `--color-on-secondary` |
| Accent/CTA | `#A16207` | `--color-accent` |
| On Accent/CTA | `#FFFFFF` | `--color-on-accent` |
| Background | `#F8FAFC` | `--color-background` |
| Foreground | `#0F172A` | `--color-foreground` |
| Card | `#FFFFFF` | `--color-card` |
| Card Foreground | `#0F172A` | `--color-card-foreground` |
| Muted | `#E9EEF5` | `--color-muted` |
| Muted Foreground | `#475569` | `--color-muted-foreground` |
| Border | `#CBD5E1` | `--color-border` |
| Destructive | `#DC2626` | `--color-destructive` |
| On Destructive | `#FFFFFF` | `--color-on-destructive` |
| Ring | `#1E3A5F` | `--color-ring` |

**Color Notes:** Institutional navy + research accent + serif headings (superseded)

### Typography (superseded — do not use)

- **Heading Font:** EB Garamond
- **Body Font:** Crimson Text
- **Mood:** academic, old-school, university, research, serious, traditional

---

## ✅ Approved Color Palette

Sourced from `resources/css/app.css` `:root` — these are real CSS custom
properties already shipping across every dashboard (patient, nurse,
physician, admin) and auth page, and are registered as Tailwind theme colors
in `tailwind.config.js` (`theme.extend.colors.brand` / `.clsu`) so every
Tailwind feature — `hover:`, `focus-visible:`, `/opacity` modifiers — works
on them, not just the literal classes hand-written in `app.css`.

| Semantic role | Token (Tailwind class name) | Hex | Used for |
|---|---|---|---|
| `primary` | `brand-green` | `#0f6b3d` | CTAs, links, active states, key icon fills |
| `primary-dark` | `brand-green-deep` | `#0a4d2d` | Hover/pressed states, high-contrast text on light-green surfaces, dark footer/hero surfaces |
| `primary-light` / `surface-brand` | `brand-green-soft` | `#edf8f0` | Tinted section backgrounds, badges, hover fills |
| `secondary` / `accent` | `brand-gold` | `#d9b648` | Sparing institutional accent — dots, dividers, secondary icon fills. Never a button's only color cue. |
| `accent-light` | `brand-gold-soft` | `#fff7dc` | Tinted info panels/badges, alternating section backgrounds |
| `surface` | `white` | `#ffffff` | Cards, primary content surfaces |
| `surface-muted` | `brand-muted` | `#f4f7f4` | Neutral section backgrounds that still feel warm, not stark white |
| `border` | `brand-border` | `#dfe9e0` | All card/section borders (replaces `gray-200`) |
| `text` | `gray-900` (Tailwind default) | — | Headings, primary body text |
| `text-muted` | `gray-600` / `gray-500` (Tailwind default) | — | Supporting copy, timestamps |
| `success` | `green-600`/`emerald-600` (Tailwind default) | — | Positive status states (already used this way elsewhere in the app) |
| `warning` | `amber-500`/`yellow-*` (Tailwind default) | — | Attention states (matches existing dashboard usage) |
| `danger` | `red-600` (Tailwind default, `x-button-danger` component) | — | Destructive/error states |

Also present: `--clsu-green` (`#008000`) / `--clsu-gold` (`#FFD700`) — a
brighter, flatter pair used specifically for the mobile bottom-nav active
tab (`layouts/navigation.blade.php`). Keep using `brand-green`/`brand-gold`
for everything else; don't mix the two green scales within one surface.

**Color budget target:** ~60–70% neutral/light surfaces (white,
`brand-muted`, card interiors), ~20–30% brand tint at the section level
(`brand-green-soft`, `brand-gold-soft`, the dark `brand-green-deep`
footer), ~5–10% solid accent (buttons, badges, icon fills). Apply color at
the **section** level, not by recoloring every card — see
`pages/welcome.md` for the applied example.

### Typography (approved)

- **Font:** Figtree (`font-sans`), loaded via bunny.net exactly as
  `layouts/app.blade.php` and `layouts/guest.blade.php` already load it —
  weights 400/500/600/700.
- One sans-serif system-wide. Do not introduce a second typeface.

### Spacing Variables

*Density: 3/10 — Spacious*

| Token | Value | Usage |
|-------|-------|-------|
| `--space-xs` | `4px` / `0.25rem` | Tight gaps |
| `--space-sm` | `8px` / `0.5rem` | Icon gaps, inline spacing |
| `--space-md` | `24px` / `1.5rem` | Standard padding |
| `--space-lg` | `32px` / `2rem` | Section padding |
| `--space-xl` | `48px` / `3rem` | Large gaps |
| `--space-2xl` | `64px` / `4rem` | Section margins |
| `--space-3xl` | `96px` / `6rem` | Hero padding |

### Shadow Depths

| Level | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | `0 1px 2px rgba(0,0,0,0.05)` | Subtle lift |
| `--shadow-md` | `0 4px 6px rgba(0,0,0,0.1)` | Cards, buttons |
| `--shadow-lg` | `0 10px 15px rgba(0,0,0,0.1)` | Modals, dropdowns |
| `--shadow-xl` | `0 20px 25px rgba(0,0,0,0.15)` | Hero images, featured cards |

---

## Component Specs

> ⚠️ **Conflicts with the current authoritative direction — do not use this
> section for new work.** The CSS below still carries the superseded navy
> palette (`#1E3A5F`, `#A16207`, `#F8FAFC` — see "Superseded auto-generated
> match" above) and gives every card `cursor: pointer` +
> `translateY(-2px)` on hover unconditionally, contradicting this same
> file's own Anti-Patterns list two sections down ("no layout-shifting
> hovers"). Real, current button/card specs — extracted from what the app
> actually ships — live in
> `design-system/clsu-telemedicine/pages/soft-modern-clinical-saas.md`
> § Buttons/Cards, and as of Phase 4 are also implemented as real
> components at `resources/views/components/button-{primary,secondary,
> danger,ghost}.blade.php`. Kept here only for the historical record of
> what this file's original auto-generation produced.

### Buttons

```css
/* Primary Button */
.btn-primary {
  background: #A16207;
  color: white;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  transition: all 200ms ease;
  cursor: pointer;
}

.btn-primary:hover {
  opacity: 0.9;
  transform: translateY(-1px);
}

/* Secondary Button */
.btn-secondary {
  background: transparent;
  color: #1E3A5F;
  border: 2px solid #1E3A5F;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  transition: all 200ms ease;
  cursor: pointer;
}
```

### Cards

```css
.card {
  background: #F8FAFC;
  border-radius: 12px;
  padding: 24px;
  box-shadow: var(--shadow-md);
  transition: all 200ms ease;
  cursor: pointer;
}

.card:hover {
  box-shadow: var(--shadow-lg);
  transform: translateY(-2px);
}
```

### Inputs

```css
.input {
  padding: 12px 16px;
  border: 1px solid #E2E8F0;
  border-radius: 8px;
  font-size: 16px;
  transition: border-color 200ms ease;
}

.input:focus {
  border-color: #1E3A5F;
  outline: none;
  box-shadow: 0 0 0 3px #1E3A5F20;
}
```

### Modals

```css
.modal-overlay {
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(4px);
}

.modal {
  background: white;
  border-radius: 16px;
  padding: 32px;
  box-shadow: var(--shadow-xl);
  max-width: 500px;
  width: 90%;
}
```

---

## Style Guidelines

**Style:** Swiss Modernism 2.0

**Keywords:** Grid system, Helvetica, modular, asymmetric, international style, rational, clean, mathematical spacing

**Best For:** Corporate sites, architecture, editorial, SaaS, museums, professional services, documentation

**Key Effects:** display: grid, grid-template-columns: repeat(12 1fr), gap: 1rem, mathematical ratios, clear hierarchy

### Page Pattern

**Pattern Name:** Portfolio Grid

- **Conversion Strategy:** Visuals first. Filter by category. Fast loading essential.
- **CTA Placement:** Project Card Hover + Footer Contact
- **Section Order:** Hero (Name/Role) > Project Grid (Masonry) > About/Philosophy > Contact

---

## Motion

**Page Transition** (Subtle) — Trigger: route change | Duration: 200-300ms | Easing: `power1.inOut`

```js
gsap.to(main, { opacity: 0, duration: 0.2, onComplete: () => { navigate(); gsap.fromTo(main, { opacity: 0 }, { opacity: 1, duration: 0.2 }); } });
```

**Framework notes:** Pair with the router's transition hooks (Next.js App Router transitions, React Router's useNavigate, Vue Router's beforeEach/afterEach); Use matchMedia('(prefers-reduced-motion: reduce)') to skip non-essential motion and render the final state immediately

- ✅ Preload the destination route's critical assets before the exit tween finishes
- ❌ Don't block navigation on animation; cap exit duration at ~250ms so the app never feels unresponsive
- ⚡ Exit animation should always resolve faster than entrance (asymmetric timing) so back/forward feels snappy

---

## Anti-Patterns (Do NOT Use)

- ❌ Low hierarchy
- ❌ no publication filtering
- ❌ cluttered visuals

### Additional Forbidden Patterns

- ❌ **Emojis as icons** — Use SVG icons (Heroicons, Lucide, Simple Icons)
- ❌ **Missing cursor:pointer** — All clickable elements must have cursor:pointer
- ❌ **Layout-shifting hovers** — Avoid scale transforms that shift layout
- ❌ **Low contrast text** — Maintain 4.5:1 minimum contrast ratio
- ❌ **Instant state changes** — Always use transitions (150-300ms)
- ❌ **Invisible focus states** — Focus states must be visible for a11y

---

## Pre-Delivery Checklist

Before delivering any UI code, verify:

- [ ] No emojis used as icons (use SVG instead)
- [ ] All icons from consistent icon set (Heroicons/Lucide)
- [ ] `cursor-pointer` on all clickable elements
- [ ] Hover states with smooth transitions (150-300ms)
- [ ] Light mode: text contrast 4.5:1 minimum
- [ ] Focus states visible for keyboard navigation
- [ ] `prefers-reduced-motion` respected
- [ ] Responsive: 375px, 768px, 1024px, 1440px
- [ ] No content hidden behind fixed navbars
- [ ] No horizontal scroll on mobile
