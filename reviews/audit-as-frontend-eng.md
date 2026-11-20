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

The first round fixed four gaps. The second round did the terms first left alone, wherever there was a screen for them. Font fallback metrics need a dev dependency and still wait for the owner's decision.

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

## Second Round: The Terms First Left Alone

The owner asked for the terms first marked "not applicable" to be done too, and changed the rule so that a UI need free Flux doesn't cover gets hand-built (Flux Pro stays out).

| Term | Where it applies | Change | Status |
|------|------------------|--------|--------|
| Kerning | All text | `font-kerning: normal` on `html`, so browsers that skip kerning on small text when left to decide still apply the font's pairs. | Done |
| Optical centre | Every avatar | The `text-box: trim-both cap alphabetic` trim on initials moved from the announcement avatar to all Flux avatars (sidebar, menus, lists), so the letters sit in the optical centre of the circle. | Done |
| Optimistic update | Notifications | One `data-read` attribute now drives the row's unread look (pastel fill, weight, icon colour, gold dot, "Unread:" for screen readers). Opening a notice or using Mark as read/unread flips it at once, and Delete hides the row at once. The server render confirms the change, and a failed request puts the row back on the next render. | Done |
| Touch target | All pages at 375px | A scan of 15 pages found only the skip link at 40px. It is now 44px on phones. The faculty-list links read 32px, but each stretches over its 48px row. | Done |
| Leading (app-wide) | All 14px text, all Flux headings, the password checklist | `--text-sm--line-height: 1.5` (was 1.43). Flux headings take `--tw-leading: 1.25`, so a `leading-*` class still wins. The password checklist drops `leading-5` for the 1.33 default. The page title's seal comment was updated for the 30px name. | Done |
| Origin-aware animation | Every Flux dropdown menu | Menus fade and scale from 96% out of the corner nearest their trigger, read from the dropdown's `position` attribute ("bottom end" opens from the top right). The animation uses `scale`, not `transform`, so Flux's placement is untouched: the menu's right edge still matches the trigger's. Reduced motion turns it off. | Done |
| OKLCH | Brand green and gold scales | Both 11-step seal scales are written in OKLCH. Every value converts back to the exact hex it replaced, so nothing changes visually. | Done |
| Stagger | A member's project list (dashboard), earlier history (project page), activity-log runs | Rows revealed together fade in 30ms apart, capped at eight steps, using `@starting-style` transitions. Only rows hidden at page load take part, so nothing animates on load. Reduced motion turns it off. | Done |
| Line length | Notification messages, the Google Drive accounts intro | Capped at 75ch. A scan of 15 pages at 1280px found no other paragraph past about 85 characters a line. | Done |
| Affordance | Sortable table headings | The whole heading sorts when clicked, so it all now shows the pointer, not just the button inside. A scan found no other clickable element without one. | Done |
| Combobox | Proponent picker (project form), co-author picker (publication form) | New `x-combobox` Blade component with an Alpine `combobox`, styled on `flux:input` and following the WAI-ARIA combobox pattern. Type any part of a name to narrow the list, arrows to move, Enter or a tap to pick, Escape to close, leave it empty to clear. It binds through `wire:model` with `x-modelable`. The short lists (colleges, years, roles) stay native selects. | Done |
| Interruptible animation | All new motion | Menus and staggered rows use transitions, which reverse from where they are when interrupted. | Done |
| Shared element transition | Project title, from the Submissions list, the Research Drive list and the dashboard into the project page | Uses Livewire 4's built-in `wire:transition.navigate="project-{id}"`, so the title morphs into the project page's heading on `wire:navigate`. Livewire skips it for reduced motion. | Done |
| Safe area | None | The viewport has no `viewport-fit=cover`, so browsers keep every page inside the safe area already. Turning on edge-to-edge would mean padding every Flux edge element (sidebar, header, flyouts, toasts) for no gain. | Not done: no screen needs it |
| Progressive disclosure | Already in use | Admin sidebar folds, activity-log runs, memo folds, per-member project lists, earlier history, the review flyout. The optional publication fields (volume, issue, pages) stay visible, because hiding citation details would leave records incomplete. | No change |

## Render Path Analysis

Most fixes are CSS only. Three change behaviour:

- **Notifications:** a click writes `data-read` on the row before the request goes out. Livewire's morph then sets the attribute to the server's value, so the server always has the last word, and no Alpine state can go stale.
- **Combobox:** the option list is passed once through `@js` and filtered in Alpine. Typing causes no Livewire round trip. Only a pick updates the bound property.
- **Shared element transition:** Livewire names the old and new titles and wraps the page swap in `document.startViewTransition`.

For the first round's fixes, Livewire's render path doesn't change. The sidebar badges update through Alpine `x-text` on the open-work poll. Tabular figures keep each badge pill one width, so a count going from 9 to 10, or from 11 to 17, no longer nudges the label next to it. Pager numbers re-render on every page change, and they now keep one width per digit. `text-wrap-style` is computed at layout time and costs nothing on re-render.

## Type Safety Assessment

Not applicable. There is no TypeScript layer. `resources/js/app.js` gains the plain-JS `combobox` Alpine component next to the memo viewer.

## Verdict Details

- Component Architecture: PASS
- State Management: PASS (Livewire for server state, Alpine for local UI state)
- Type Safety: N/A
- Rendering Performance: PASS
- Bundle Impact: PASS (memo renderers are already imported lazily)
