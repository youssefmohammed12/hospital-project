# HealthBridge Milestone 3 — Home/Doctors mobile navigation parity

**Date:** 20 November 2026  
**Status:** Implemented locally; automated parity and accessibility checks passed  
**Scope:** Public Home and Doctors mobile navigation only

## What was wrong

The two pages looked similar on desktop but did not share the same mobile-menu rules.

- Home's earlier responsive rules were being overridden by a later breakpoint block, so the dropdown inherited a different surface/shadow and retained old global menu behavior.
- The Doctors dropdown inherited bottom padding on its action row, which made its panel 12px taller. Its mobile flex row also retained desktop spacing behavior and its links were not using the same display/alignment rules as Home.
- The page-level controllers did not expose the same accessible toggle label state: Doctors updated `aria-expanded` but did not update the button's Open/Close label.

The earlier responsive checks verified that both menus could open. They did not compare the actual panel geometry and computed styles between pages. That gap allowed a shared-component inconsistency to pass.

## Changes made

**Home — `assets/css/pages/home.css`**
- Consolidated the mobile navigation styling into the existing 768px responsive block instead of leaving a second competing mobile rule set.
- Matched the Doctors menu geometry: full-width links, consistent spacing/padding, aligned action row and 38px toggle controls with 18px icons.
- The open panel now uses the same deep-green surface and shadow in dark and light themes.

**Doctors — `assets/css/pages/doctors.css`**
- Reset mobile flex distribution to start alignment.
- Matched link display/alignment and removed the inherited bottom action-row padding.
- Kept the menu panel deep green in both themes.

**Doctors controller — `assets/js/pages/doctors.js`**
- Toggle now keeps `aria-controls`, `aria-expanded` and the accessible Open/Close button label in sync, matching Home behavior.

Cache references:
- Home CSS: `assets/css/pages/home.css?v=19`
- Doctors CSS: `assets/css/pages/doctors.css?v=9`
- Doctors controller: `assets/js/pages/doctors.js?v=8`

No navigation IDs or link destinations changed.

## Verification

The reusable runner is now stored outside the app repository at:
`D:\dev-toolkit\scripts\healthbridge-public-mobile-nav-parity.mjs`

Latest run against the local site:

- 20 layout cases: 2 pages × 5 widths (320, 390, 560, 561, 768) × 2 themes.
- 10/10 Home-versus-Doctors computed-style and geometry comparisons passed.
- 20/20 menu-open states and ARIA labels passed.
- 20/20 pointer-close states passed; `aria-expanded` returned to false and the label returned to “Open navigation menu”.
- 20/20 keyboard open/close cycles passed (Enter and Space).
- Zero horizontal overflow; both menus remained deep green.
- Four mobile axe-core WCAG 2.0/2.1 A/AA scans: zero violations.
- Zero page errors, local HTTP failures or blocked API mutation attempts.

Raw machine-readable results:
`C:\xampp85\migration-backups\healthbridge\milestone3-mobile-nav-parity-20261120\mobile-nav-parity.json`

Source backup folder:
`C:\xampp85\migration-backups\healthbridge\milestone3-mobile-nav-parity-20261120\source-before\`

The Doctors stylesheet snapshot in that folder was reconstructed by reversing only this task's navigation patch; a direct diff confirmed that the live stylesheet differs from that snapshot only by the intended mobile-navigation declarations. The manifest records the snapshot hash. Other source files were copied before editing.

`git diff --check` passed for the edited source files. Git emitted only the known LF-to-CRLF working-copy warnings for Home CSS and index.html.

## Files changed

Inside HealthBridge:
- `assets/css/pages/home.css`
- `assets/css/pages/doctors.css`
- `assets/js/pages/doctors.js`
- `index.html`
- `doctors.html`
- `local working handoff`
- This result document.

Outside HealthBridge:
- `D:\dev-toolkit\scripts\healthbridge-public-mobile-nav-parity.mjs`

No shared `assets/css/style.css` or `assets/js/core/main.js`, PHP, API, database, auth/session, RBAC or dashboard code was changed. No dependency was added. No commit, push or deployment was made.

## Process correction before the next public page

We should not repeat the cycle of styling each page independently and discovering shared-component drift during owner review.

Before moving to the next public page, the next foundation task should extract the public header/mobile navigation styles into one shared component stylesheet and centralize its accessible toggle-state behavior. Every page using that component should then run the same regression gate:

1. Cross-page visual/computed-style parity at the supported mobile breakpoints.
2. Light and dark themes.
3. Pointer and keyboard open/close behavior plus ARIA state.
4. Axe accessibility scan and overflow/error checks.

Only after the shared shell passes should page-specific sections be accepted. The reusable parity runner created for this fix is the first gate; it should be extended as more public pages adopt the shared component.

**Owner review gate:** Please compare the Home and Doctors mobile menus at a narrow phone width and around the 560/561px breakpoint, in both themes. Check that the panel width, link width, spacing, action buttons, toggle icon and close/reopen behavior now match. Stop here until review.
