# HealthBridge — Patient Dashboard Phase 0: Shell

**Date:** 22 November 2026  
**Status:** Phase 0 implementation and focused QA complete; awaiting owner review. Phase 1 has not started.

## Scope

This phase establishes the patient workspace shell and removes the legacy frontend asset dependencies from these nine patient workspace routes:

- `pages/patient/dashboard.html`
- `pages/patient/appointments.html`
- `pages/patient/downloads.html`
- `pages/patient/history.html`
- `pages/patient/insights.html`
- `pages/patient/notifications.html`
- `pages/patient/prescriptions.html`
- `pages/patient/profile.html`
- `pages/patient/settings.html`

The separate `pages/patient/patient-emr.html` is an admin-facing EMR route with its own admin navigation. It is intentionally outside the patient workspace scope and was not changed.

## Foundation Rule — implemented

Created two new patient-dashboard-owned Public V2 assets:

- `assets/public-v2/css/pages/patient-dashboard.css`
- `assets/public-v2/js/core/patient-shell.js`

The nine route documents now load only these four stylesheets:

1. `assets/public-v2/css/tokens.css?v=1`
2. `assets/public-v2/css/base.css?v=1`
3. `assets/public-v2/css/components.css?v=10`
4. `assets/public-v2/css/pages/patient-dashboard.css?v=2`

And only these two scripts:

1. `assets/public-v2/js/core/site.js?v=1`
2. `assets/public-v2/js/core/patient-shell.js?v=1`

The shared `site.js` is an approved Public V2 runtime. The new patient shell owns the authenticated header, sidebar, menu state, role/session gate, active navigation indicator and route-level loading state. It does not fetch the old navbar/sidebar fragments or load `assets/js/components/layout.js`.

A static scan of all nine patient routes found zero old `assets/css/**` or `assets/js/**` references, zero external CDN/font references, zero inline event handlers, zero inline style attributes and zero inline style blocks. Each route has exactly four stylesheet links and two script elements. The old legacy files remain on disk for other routes; they were detached from the patient workspace, not deleted globally.

## Shell changes

- Replaced the public-site navigation with a patient-only workspace header. The sidebar's **View Site** link is the exit to the public website.
- Replaced the plain patient-name text with an avatar + account-name profile-menu trigger. Its menu contains **Profile**, **Settings** and **Logout**.
- Removed the redundant Dashboard and Logout header pills. Logout exists once, inside the profile menu.
- Sidebar item and page heading now use the same **Document Center** label for `downloads.html`.
- Removed the duplicated settings-gear modal from this shell by removing its legacy layout injection. The header gear, profile menu and sidebar Settings link all navigate to the single `settings.html` route.
- Sidebar **Health Insights** remains during this phase; the owner-directed merge/removal is reserved for Phase 5.
- The Settings page remains one route. Its full form/tab remake is reserved for Phase 6.

## Shell animation set

- Sidebar active indicator: transform-based movement between the previous and current active route, with a 220ms transition.
- Route entry: a 200ms opacity + translateY reveal starts only after the session identity resolves; the main content does not animate underneath the loading skeleton.
- Route loading: a shell skeleton overlay appears while the session check is pending, then fades away.
- `prefers-reduced-motion: reduce` disables the shell movement, route reveal and skeleton pulse.
- Motion is restricted to transform and opacity. No layout properties are animated.

## Session and identity behavior

The shell uses the existing Public V2 runtime helpers and requests `GET api/auth/current_user.php` with same-origin credentials. It displays the canonical server-returned patient name and derived initials, redirects authenticated non-patient roles to their own workspace, and redirects missing/invalid sessions to the existing login page. Logout delegates to the existing Public V2 `logoutUser()` function. A dedicated browser smoke test clicked the profile-menu Logout item with the existing logout endpoint intercepted: exactly one expected POST, redirect to Login, both cached identity keys cleared, zero external requests and zero page exceptions. No real logout/session mutation was sent to the backend. No PHP/API/auth/session/CSRF/RBAC/database implementation was changed.

## Important phase boundary

This is a shell migration, not the page-feature rebuild. Under the owner's Foundation Rule, the old patient page controllers and their legacy module scripts have been detached immediately; they are not loaded even temporarily.

Consequently, the original route-body markup and its prior loading placeholders have been preserved for the upcoming phase work, but old JavaScript-driven page features are not yet reconnected. Some route bodies can therefore remain at their existing placeholder/static state until their corresponding phase supplies new Public V2 page behavior. This is intentional for the phase-by-phase migration and is not presented as a completed feature migration. In particular, the notification dropdown currently provides shell structure only; its live notification content and read-state synchronization belong to Phase 4.

## QA and verification

**Final Playwright run: PASS**

- 54 route/theme/viewport states: 9 routes × 3 widths (1440, 390, 320) × dark/light.
- 1,156/1,156 assertions passed; zero failed assertions.
- One axe WCAG 2.0/2.1 A/AA scan of the patient header/sidebar: zero violations.
- Mobile interactions verified: sidebar open/close via toggle, Escape and backdrop; profile menu click and keyboard open/navigation; notification dropdown open/outside-click close; shared V2 theme toggle.
- Delayed session response confirms the shell skeleton is visible while identity resolves, content remains static behind it, and the 200ms fade-rise begins when identity is ready.
- Reduced-motion state confirms shell animations/transitions are disabled.
- Header gear navigation reaches the single Settings route.
- Zero page exceptions, zero console errors in the mocked authenticated route states, zero external requests, zero failed requests and zero unmocked POST requests. One theme-preference POST was intercepted with a mocked success response; no real preference write was made.
- No horizontal overflow at 320px, 390px or 1440px.

**Unauthenticated guard: PASS**

A separate no-cookie browser check received HTTP 401 from the existing current-user endpoint and redirected to `/pages/auth/login.html`. It made no POST or external request, did not leave a cached `hb_user`, and produced no page exception. The browser emitted its expected resource-level 401 console warning for the intentional unauthenticated request.

**Source preservation: PASS**

A byte/text-preservation comparator confirmed all nine route documents match their original backed-up content after normalization for exactly the required stylesheet/script tag replacement: 9/9 passed. This check was added after an initial PowerShell UTF-8 rewrite issue was detected; the original route files were restored from the snapshot and the asset-only replacements were reapplied using UTF-8-safe Node file I/O. Non-asset page markup was preserved.

**Additional static checks**

- JavaScript syntax check passed for `patient-shell.js` and the Playwright runner.
- All nine route files contain no inline style blocks, style attributes or inline event-handler attributes.
- The final loaded-asset scan found zero legacy CSS/JS references in all 54 browser states.
- No screenshots were taken or saved.

## Evidence and rollback

- Detailed QA report: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase0-shell-20261122\qa\patient-dashboard-phase0-audit.json`
- Playwright runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-phase0-audit-20261122.mjs`
- Unauthenticated guard runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-unauth-guard-20261122.mjs`
- Mocked profile-menu Logout smoke runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-phase0-logout-smoke-20261122.mjs`
- Original route backup + SHA-256 manifest: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase0-shell-20261122\source-before\`
- Final route/CSS/JS snapshot + SHA-256 manifest: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase0-shell-20261122\source-after\`
- Asset-only rewiring script: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-phase0-rewire-20261122.cjs`
- Source-preservation comparator: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-phase0-source-preservation-20261122.cjs`

## Owner review gate

Phase 0 shell implementation was completed on 22 November. Its follow-up correction report supersedes this initial handoff for per-page coverage, the restored Dashboard body and the patient Settings cleanup: `docs/HEALTHBRIDGE_PATIENT_DASHBOARD_PHASE_0_CORRECTION_20261122.md`. The correction reran an explicit 9/9 page shell matrix and completed the three requested correction jobs. Phase 1 remains paused until the owner reviews and approves the corrected result. No database or backend contracts were changed. No screenshots, staging, commit, push or deployment were performed.

Legacy references on this page: none.
