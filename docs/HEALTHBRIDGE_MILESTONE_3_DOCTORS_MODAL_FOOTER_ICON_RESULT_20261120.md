# HealthBridge — Doctors dialog, shared footer and search icon fix

**Date:** 20 November 2026  
**Scope:** Public Home and Doctors pages; Doctors profile dialog behavior  
**Status:** Implemented and automated local regression checks passed; awaiting owner review  
**Routes:** `http://localhost/optimized/` and `http://localhost/optimized/doctors.html`

## Doctors profile dialog — cause and fix

The profile dialog is a fixed, full-viewport overlay. While it is open, the page background is intentionally made `inert` and `aria-hidden` so keyboard and pointer interaction stays inside the dialog. The existing close button and Escape handler restored the page correctly, but the overlay had no backdrop-click dismissal. Clicking outside the profile panel therefore continued to hit the active overlay while the rest of the page remained inert. This looked like the entire page had become unclickable.

Changes in `assets/js/pages/doctors.js`:
- A click on the backdrop itself now closes the dialog. Clicking inside the profile panel does not.
- The background-state capture is now idempotent: opening an already-open dialog cannot overwrite the saved original inert/ARIA states with the temporary locked state.
- Restoration clears the saved snapshot before applying the previous states, and body scroll-lock removal is unconditional.
- Existing close-button, Escape, focus-trap and focus-return behavior remain intact.

## Footer and search icon polish

Both public page footers use the same slightly tighter vertical rhythm, without reducing type size:
- Desktop footer height in the local 1440px browser check: 391px before, 352.5px after (38.5px shorter).
- Mobile at 390px: Home 731px before and 674.8px after; Doctors 728px before and 674.8px after.
- Footer typography, wording, links, colors and deep-green surface remain unchanged.

The Home and Doctors search spans retain their existing classes and accessible-hidden semantics, but their text glyph was replaced with the same small inline SVG magnifier. Each icon is centered in a 24×24px flex box; the SVG is 18×18px. Browser measurements showed a 0px horizontal and vertical center delta in the tested layouts.

## Verification

**Focused modal interaction check: PASS**
- Opening the profile makes the page background inert and moves focus into the dialog.
- Clicking inside the dialog keeps it open.
- Close button restores the page, removes `inert` and `aria-hidden`, returns focus to the trigger, and makes search/sort controls clickable.
- Repeated/programmatic open calls preserve the original background state.
- Clicking the backdrop closes the dialog and restores page interaction.
- Escape closes the dialog and restores page interaction.

**Doctors full regression audit: PASS**
- HTTP 200; 32 API-backed sample profiles.
- Three unique read-only API endpoints returned HTTP 200.
- 12 responsive light/dark viewport combinations: 320, 360, 390, 768, 1024 and 1440px; zero horizontal overflow, no grid/footer/brand failures.
- 14 axe WCAG 2.0/2.1 A/AA scans: zero violations.
- 28/28 interaction checks passed.
- Hero/section/dynamic-card motion 3/3; reduced motion 5/5; missing-IntersectionObserver fallback 4/4.
- No failed local assets, API mutation requests, browser page exceptions or non-CSP console errors.

**Home + Doctors shared polish audit: PASS**
- 24 responsive/theme combinations across both pages (six widths × two themes × two pages): zero horizontal overflow.
- Search SVG position: centered within measurement tolerance at every tested size/theme (actual measured center delta 0px).
- Eight additional axe scans (both pages, 390px and 1440px, light/dark): zero violations.

The full Doctors run reports existing shared-stylesheet CSP warnings. The legacy Sora import remains blocked by the current policy, along with an existing inline-style warning. No CSP rule or shared stylesheet was changed in this pass.

## Files changed

- `assets/js/pages/doctors.js`
- `assets/css/pages/doctors.css`
- `assets/css/pages/home.css`
- `doctors.html`
- `index.html`
- `local working handoff` — current handoff/status note
- This report

Cache references:
- Home: `assets/css/pages/home.css?v=17`
- Doctors: `assets/css/pages/doctors.css?v=8`
- Doctors controller: `assets/js/pages/doctors.js?v=7`

No IDs/classes were renamed. Existing `.home-search__icon` and `.directory-search__icon` hooks remain. The shared main.js, shared style.css, PHP, APIs, database, auth/session, RBAC and dashboards were not changed. No dependencies were added. No Git staging, commit, push, deployment, branch/reset/clean/stash operation or database mutation was performed.

## Backup and evidence

Before-edit backups with SHA-256 manifest:
`C:\xampp85\migration-backups\healthbridge\milestone3-doctors-modal-footer-icon-fix-20261120\source-before\`

Automated evidence:
`C:\xampp85\migration-backups\healthbridge\milestone3-doctors-modal-footer-icon-fix-20261120\doctors-premium-audit.json`

The temporary, readiness-corrected audit runner is under `D:\dev-toolkit\qa-temp\`, not in the HealthBridge repository.

**Owner review gate:** Please test the profile using the close button, Escape and a click on the dark backdrop; then confirm the directory remains interactive. Review both footers and search icons on Home and Doctors in light/dark themes at desktop and mobile widths. The changes are local only; no deployment was made.
