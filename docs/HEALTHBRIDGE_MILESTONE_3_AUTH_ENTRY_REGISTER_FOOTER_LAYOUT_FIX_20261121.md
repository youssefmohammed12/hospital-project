# HealthBridge Milestone 3 — Auth Entry Heading and Footer Layout Fix

**Date:** 21 November 2026  
**Routes:** `pages/auth/register.html`, `pages/auth/login.html`  
**Status:** Applied; focused responsive and accessibility checks PASS.

## Fixes

### Register heading

Removed the desktop-only sticky positioning and the backing pseudo-element from the Register story headline. It now stays in normal document flow at every breakpoint and scrolls away with the rest of the left panel. The fixed header remains unchanged.

### Empty strip after the footer

The gap was not footer padding or a margin. The shared `main.js` creates a Back-to-top button on every page, but the auth-entry stylesheet does not load the legacy stylesheet that normally positions and styles that control. On Login and Register, the unstyled native button was therefore laid out statically immediately after the footer, adding a 29px pale rectangle to the bottom of the document.

The auth-entry stylesheet now hides that unused control on both auth routes. `main.js` itself was not changed. The shared footer remains the canonical fragment and retains all its existing content and spacing.

Both entry pages now load `auth-entry.css?v=4`.

## Focused QA

Runner: `D:\dev-toolkit\scripts\healthbridge-auth-entry-register-footer-layout-fix-audit-20261121.mjs`  
Evidence: `C:\xampp85\migration-backups\healthbridge\auth-entry-register-footer-layout-fix-20261121\qa\layout-fix-audit.json`

- **184/184 assertions PASS** across 20 states: Login and Register × 320/390/768/1024/1440px × dark/light.
- **20 axe WCAG 2.0/2.1 A/AA scans**, zero violations.
- Register's heading computes to `position: static` at desktop/tablet and phone widths; scroll checks at 768/1024/1440px confirm it moves naturally above and out of the fixed header.
- The Back-to-top button still gets created by the existing shared script but computes to `display: none` and zero height on both auth pages.
- Across all 20 states, the document bottom matches the footer bottom within 0.44px of browser subpixel rounding. The former ~29px control gap is gone.
- Zero horizontal overflow, browser errors, console errors, failed requests, external requests or POST requests.

## Scope and backup

Only these application files changed in this fix:

- `assets/css/pages/auth-entry.css`
- `pages/auth/register.html` — cache query only
- `pages/auth/login.html` — shared stylesheet cache query only

No backend/API/database/security/session/controller logic or shared JavaScript changed. Pre-edit copies and SHA-256 manifest are stored under:

`C:\xampp85\migration-backups\healthbridge\auth-entry-register-footer-layout-fix-20261121\source-before\`

No screenshots were created or saved. No Git stage, commit, push or deploy was performed.
