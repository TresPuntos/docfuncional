# 05 — Design Tokens

> Visual identity system for Tres Puntos digital products.
> This is the **single source of truth** for colors, typography, spacing, and visual patterns.
> All projects and AI agents MUST use these tokens when generating UI or styled content.

---

## CSS Custom Properties (`:root`)

```css
:root {
  /* ── Brand ── */
  --mint: #5dffbf;
  --mint-hover: #49e6a8;
  --mint-rgb: 93, 255, 191;

  /* ── Backgrounds ── */
  --bg-base: #0e0e0e;
  --bg-surface: #141414;
  --bg-subtle: #191919;
  --bg-muted: #1f1f1f;

  /* ── Text ── */
  --text-primary: #f5f5f5;
  --text-secondary: #b3b3b3;
  --text-muted: #8a8a8a;
  --text-inverse: #0e0e0e;

  /* ── Borders ── */
  --border-base: #1f1f1f;
  --border-subtle: #1a1a1a;
  --border-strong: #2a2a2a;
  --border-focus: var(--mint);

  /* ── Typography ── */
  --font-heading: 'Plus Jakarta Sans', sans-serif;
  --font-body: 'Inter', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;

  /* ── Border Radius ── */
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-xl: 20px;
  --radius-2xl: 24px;
  --radius-full: 9999px;

  /* ── Transitions ── */
  --transition-fast: .15s ease;
  --transition-base: .25s ease;
  --transition-spring: .4s cubic-bezier(0.34, 1.56, 0.64, 1);

  /* ── Shadows ── */
  --shadow-elevation: 0 8px 32px rgba(0,0,0,.6), 0 2px 8px rgba(0,0,0,.4);
  --shadow-brand: 0 0 24px rgba(93,255,191,.2);

  /* ── Gradients ── */
  --gradient-brand: linear-gradient(135deg, #5dffbf 0, #4ea5ff 50%, #c084fc 100%);

  /* ── Spacing ── */
  --text-body: 1rem;
  --text-sm: .875rem;
  --space-2: .5rem;
}
```

---

## Light Theme Override

When `[data-theme="light"]` is applied to the root element:

```css
[data-theme="light"] {
  --bg-base: #ffffff;
  --bg-surface: #f8f9fa;
  --bg-subtle: #f1f3f5;
  --bg-muted: #e9ecef;
  --text-primary: #111827;
  --text-secondary: #4b5563;
  --text-muted: #9ca3af;
  --text-inverse: #ffffff;
  --border-base: #e5e7eb;
  --border-subtle: #f3f4f6;
  --border-strong: #d1d5db;
  --mint: #059669;
  --mint-hover: #047857;
  --mint-rgb: 5, 150, 105;
  --shadow-elevation: 0 4px 16px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
  --shadow-brand: 0 0 24px rgba(5,150,105,.15);
  --gradient-brand: linear-gradient(135deg, #059669 0%, #2563eb 50%, #7c3aed 100%);
}
```

---

## Google Fonts Import

```html
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500&family=Plus+Jakarta+Sans:wght@700&family=JetBrains+Mono:wght@400&display=swap"
  rel="stylesheet" media="print" onload="this.media='all'" />
```

Performance tip: use `media="print"` with `onload` for non-blocking font load.

Font fallbacks for CLS prevention:
```css
@font-face { font-family: 'Inter Fallback'; src: local('Arial'); size-adjust: 107%; ascent-override: 90%; descent-override: 22%; line-gap-override: 0%; }
@font-face { font-family: 'Jakarta Fallback'; src: local('Arial Black'); size-adjust: 95%; ascent-override: 100%; descent-override: 22%; line-gap-override: 0%; }
```

---

## Typography Scale

| Level | Font | Size | Weight | Letter-spacing | Line-height |
|-------|------|------|--------|---------------|-------------|
| Hero / H1 | Plus Jakarta Sans | `clamp(1.75rem, 3vw, 2.5rem)` | 800 | -0.03em | 1.15 |
| Section H2 | Plus Jakarta Sans | `clamp(1.75rem, 3vw, 2.5rem)` | 800 | -0.03em | 1.15 |
| Section sub | Inter | `1rem` | 400 | normal | 1.7 |
| Body | Inter | `1rem` | 400 | normal | 1.6 |
| Small | Inter | `.875rem` | 400 | normal | 1.5 |
| Badge / Label | Inter | `11px` | 600 | 0.1em | 1 |
| Mono / Code | JetBrains Mono | `.75rem` | 400 | normal | 1.4 |

---

## Component Tokens

### Buttons

**Primary button:**
```css
.btn-primary {
  padding: .875rem 2rem;
  background: var(--mint);
  color: #0e0e0e;
  font-family: var(--font-heading);
  font-weight: 700;
  font-size: .9375rem;
  border-radius: var(--radius-lg);
  box-shadow: 0 0 20px rgba(93,255,191,.15);
}
.btn-primary:hover {
  background: var(--mint-hover);
  box-shadow: 0 0 30px rgba(93,255,191,.25);
  transform: translateY(-1px);
}
```

**Secondary button:**
```css
.btn-secondary {
  padding: .875rem 2rem;
  background: transparent;
  color: var(--text-primary);
  font-family: var(--font-heading);
  font-weight: 600;
  font-size: .9375rem;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-lg);
}
.btn-secondary:hover {
  color: var(--mint);
  border-color: rgba(93,255,191,.4);
  transform: translateY(-1px);
}
```

**Ghost button:**
```css
.btn-ghost {
  font-size: .875rem;
  font-weight: 600;
  color: var(--mint);
}
.btn-ghost:hover { gap: .75rem; }
```

### Section Badge
```css
.section-badge {
  padding: 5px 14px;
  background: rgba(93,255,191,.08);
  border: 1px solid rgba(93,255,191,.2);
  border-radius: var(--radius-full);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--mint);
}
```

### Form Inputs
```css
.input {
  padding: .75rem 1rem;
  background: var(--bg-subtle);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  color: var(--text-primary);
  font-family: var(--font-body);
  font-size: .875rem;
}
.input:focus {
  border-color: var(--mint);
  box-shadow: 0 0 0 3px rgba(93,255,191,.08);
}
```

---

## Icon System

- **Library:** [Lucide Icons](https://lucide.dev)
- **CDN:** `https://unpkg.com/lucide@latest`
- **Usage:** `<i data-lucide="icon-name"></i>` then call `lucide.createIcons()`
- **Default:** 24px, stroke-width 2, inherits `currentColor`

---

## Animation Patterns

### Key Animations
| Name | Usage | CSS |
|------|-------|-----|
| `fadeUp` | Reveal on scroll | `translateY(20px) → 0, opacity 0 → 1` |
| `fadeInUp` | Hero elements | `translateY(32px) → 0, opacity 0 → 1` |
| `cyber-aurora` | Gradient text shimmer | `background-position` cycle, 6s infinite |
| `pulse-dot` | Badge indicator | Scale + opacity pulse, 2s infinite |
| `glowPulse` | Ambient card glow | Box-shadow pulse with mint, 3s |

### Gradient Text Effect
```css
.text-gradient .word,
.text-gradient-standalone {
  background: linear-gradient(45deg, #5dffbf 0, #4ea5ff 35%, #c084fc 70%, #5dffbf 100%);
  background-size: 300% 300%;
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: cyber-aurora 6s ease infinite;
}
```

### Scroll Reveal
```css
.reveal {
  opacity: 0;
  transform: translateY(28px);
  transition: opacity .6s ease, transform .6s ease;
}
.reveal.visible {
  opacity: 1;
  transform: translateY(0);
}
```

---

## Accessibility

- Focus ring: `outline: 2px solid var(--mint); outline-offset: 2px;`
- Touch targets: minimum 44x44px on `pointer: coarse`
- Reduced motion: all animations disabled with `prefers-reduced-motion: reduce`
- Skip link provided on all pages

---

## Responsive Breakpoints

| Name | Width | Notes |
|------|-------|-------|
| Mobile | `≤768px` | Single column, reduced padding |
| Tablet | `769–1024px` | Sidebar hidden on design system page |
| Desktop | `1025–1599px` | Default layout, `max-width: 1280px` |
| Large | `≥1600px` | `max-width: 1440px`, larger type |
| XL | `≥1920px` | `max-width: 1600px` |

---

## Usage Rules for AI Agents

1. **Always use CSS custom properties** — never hardcode hex values in generated HTML.
2. **Dark theme is default** — all tokens assume dark background unless `[data-theme="light"]`.
3. **Button contrast**: Primary buttons use `color: #0e0e0e` (near-black text on mint).
4. **Transparency pattern**: `rgba(var(--mint-rgb), 0.15)` for tinted backgrounds.
5. **Headings**: `font-family: var(--font-heading)` with weight 700-800.
6. **Body text**: `font-family: var(--font-body)` at weight 400-500.
7. **Use `rem` units** for all sizing. No `px` except for borders and shadows.
8. **CTAs**: Use vocabulary from `03-vocabulary.md` — primary: "Cuentanos tu proyecto", secondary: "Analiza tu plataforma actual".
9. **Content tone**: Follow `02-voice-and-tone.md` based on context (proposals = Partner Mode).
10. **Prohibited words**: Never use "innovador", "soluciones 360", "sinergia" etc. (see `03-vocabulary.md`).
