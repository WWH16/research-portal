# Frontend Engineering Audit: 20 design terms against the portal UI

**Perspective**: Frontend Engineer
**Date**: 2026-11-20
**Plan**: The "20 Design Terms You Should Know" checklist, applied to `resources/css/` and `resources/views/`

## Plan Summary

Check the Livewire 4 + Flux + Tailwind 4 UI against twenty typography, layout and motion terms. Fix the terms that apply here and record the others as not applicable. The checklist's React, Zustand and TypeScript parts don't apply, because this project has no React layer.

## Files Reviewed

| File | Frontend Role | Risk |
|------|--------------|------|
| `resources/css/app.css` | Theme tokens, Flux overrides, sidebar skin | Low |
| `resources/css/announcements.css` | Memo viewer and poster avatar | Low |
| `resources/js/app.js` | Alpine memo viewer and memo fold | Low |
| `resources/views/partials/head.blade.php` | Viewport, fonts | Low |
| `vite.config.js` | Font loading (`bunny('Instrument Sans')`) | Low |
| `resources/views/layouts/app/sidebar.blade.php` | Polled count badges | Low |
| `resources/views/pages/**` (all tables, selects, motion) | Pages | Low |

## Verdict: APPROVE WITH CHANGES

The UI already covered most of the twenty terms. Four small gaps were fixed. One more (font fallback metrics) needs a dev dependency and waits for the owner's decision.

## Critical Issues (Must Fix)

None.

## Recommendations

| # | Term | File(s) | Why | Fix | Status |
|---|------|---------|-----|-----|--------|
| 1 | Widow | `app.css` | Headings and paragraphs could end with one word alone on the last line. Only a few elements had `text-balance` or `text-pretty`. | One base rule: `text-wrap-style: balance` on headings and `pretty` on `p` and `dd`. The longhand leaves the wrap mode alone, so truncated headings stay on one line. The per-element utilities it replaces were removed. | Done |
| 2 | Tabular numbers | `app.css` | The sidebar badges change in place on each poll, and the pager's "Showing 11 to 20 of 42" changes on each page. Proportional digits make both change width. | `font-variant-numeric: tabular-nums` on `.isu-sidebar [data-flux-navlist-badge]` and `[data-flux-pagination]`. Tables, stat tiles and charts already had it. | Done |
| 3 | Tracking | `pages/departments/⚡index.blade.php` | The college code input forces `uppercase`. All-caps text set at normal spacing looks cramped. | `tracking-wide` on that input. | Done |
| 4 | Leading | `layouts/legal.blade.php`, `pages/auth/{login,register,forgot-password}.blade.php` | The legal title was set at a line height of 1.0, the lead paragraph at 1.78, the body at 1.75 and the section headings at 1.4. The 12px reCAPTCHA notice on the sign-in pages was at 1.67. | Title 1.1, lead paragraph and body 1.6, section headings 1.25. The notice drops `leading-5` and falls back to the `text-xs` default of 1.33. | Done |
| 5 | Layout shift, x-height | `vite.config.js`, `app.css` | Instrument Sans loads with `font-display: swap` and no metric-matched fallback. On a cold load, text reflows when the font arrives, because the system font's x-height and widths differ. The font plugin can build an `Instrument Sans fallback` face with `size-adjust`, but only with the optional `fontaine` package installed. | `npm i -D fontaine`, then point `--font-sans` at `var(--font-instrument-sans)` so the fallback face is used. The fonts are self-hosted and preloaded, so the shift is short and happens only on a first visit. | Needs owner's OK (adds a dev package) |

## Not Applicable Here

| Term | Why it doesn't apply |
|------|-----------------------|
| Kerning | Browsers kern Instrument Sans by default (`font-kerning: auto`). There is no display type that needs manual pair kerning. |
| Optical centre | Avatar initials are already trimmed with `text-box: trim-both cap alphabetic`. The seal is centred on the title block, and Heroicons are centred on their own grid. No play-triangle-style icon needs a nudge. |
| Optimistic update | Every action needs server state: opening a notice loads it, and a review decision must persist. Slow responses already dim after 150ms (`data-loading:opacity-60 delay-150`), and deletes use Undo. |
| Touch target | Phones already get 44px controls throughout (`max-sm:h-11`, `max-sm:size-11`, sidebar, pager, OTP). `ux-audit` re-checks this. |
| Leading (app-wide) | Tailwind's `text-sm` default is 1.43, a little under the 1.5 body floor. Several layouts are tuned to that 20px line, such as the page title's seal, which is sized to the name plus the subtitle line. Changing the token globally would break those, so it was left alone. `password-requirements` (12px at 1.67) was outside this pass. |
| Origin-aware animation | Flux handles its own dropdowns and modals. The custom motion is opacity fades and chevron turns, which have no origin. |
| OKLCH | The neutrals are already OKLCH, and colour mixes use oklab. The brand green and gold are hex values sampled from the seal, so converting them is churn with no visible change. |
| Safe area | The viewport has no `viewport-fit=cover`, so the browser keeps the page inside the safe area. |
| Stagger | The portal has no list entrance animations, and its "restrained" posture doesn't call for any. |
| Line length | Legal pages cap at 62ch and 68ch, flyouts at 30rem, and descriptions at `max-w-prose`. Announcement text spans the card on purpose, at the Research Office's request (waiver in `announcements.css`). |
| Affordance | Buttons get a pointer cursor globally. Rows have hover fills, and whole-row links show a focus ring. The upload drop zone shows a hover and focus state. |
| Combobox | Flux's searchable select is Flux Pro, which isn't installed. The filter lists are short (college codes, years, statuses). The faculty pickers use the native select's type-ahead. A hand-built combobox would go against the project's Laravel-first rule. Revisit only if the faculty list grows into the hundreds. |
| Interruptible animation | All motion is CSS transitions, which reverse from where they are when interrupted. There are no keyframe animations on interactive state. |
| Progressive disclosure | Already used: admin sidebar folds, activity-log runs, memo folds, per-member project toggles, and the review flyout. |
| Shared element transition | The portal has no motion system to build on, and a view transition across `wire:navigate` would add motion that its restrained posture doesn't call for. |

## Render Path Analysis

The fixes are CSS only, and Livewire's render path doesn't change. The sidebar badges update through Alpine `x-text` on the open-work poll. Tabular figures keep each badge pill one width, so a count going from 9 to 10, or from 11 to 17, no longer nudges the label next to it. Pager numbers re-render on every page change, and they now keep one width per digit. `text-wrap-style` is computed at layout time and costs nothing on re-render.

## Type Safety Assessment

Not applicable. There is no TypeScript layer. The Alpine components in `resources/js/app.js` are plain JS and weren't changed.

## Verdict Details

- Component Architecture: PASS
- State Management: PASS (Livewire for server state, Alpine for local UI state)
- Type Safety: N/A
- Rendering Performance: PASS
- Bundle Impact: PASS (memo renderers are already imported lazily)
