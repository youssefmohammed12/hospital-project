# HealthBridge — Home Demo Invitation & Shared Signal Strip

**Date:** 21 November 2026  
**Status:** Implemented locally; focused browser audit passed  
**Scope:** Home invitation copy/destinations and shared Public V2 information-strip component

## Home demo invitation

- Hero primary action now says **Try the live demo** and links to `pages/auth/login.html`.
- The doctor-directory secondary link remains unchanged.
- Added the supporting line: “Demo accounts for patient, doctor and admin — one click, no signup.”
- The About/project-overview action now says “Enter the demo — no signup needed”. Its sign-in destination is unchanged.
- Step 02 now says “Sign in with a ready-made demo account, or create a demo patient account.”
- The role-based workspaces copy now says visitors can use the demo accounts and switch between roles in one click. The word “approved” has been removed from both gated-sounding demo descriptions.
- The closing CTA heading remains unchanged. Its copy now invites visitors to sign in with the patient, doctor or admin demo accounts without signup, and **Start exploring** now links to the sign-in page.
- The header **Create account** action still links to registration exactly as before.

## Shared information-strip component

The repeated three-column strips previously had page-specific type sizes, padding and separators. A single shared component now lives in `assets/public-v2/css/components.css?v=2`.

The shared contract fixes the band surface/border, three-column desktop grid, item alignment and 16px gap, 18px/24px desktop item padding, 15px mobile row padding, separators, serif number style, and Inter/system title/detail typography. Titles are 0.82rem / 700; supporting details are 0.70rem; numerals are 1.65rem. At 768px and below the strip becomes one column.

Migrated to the shared markup and styles:
- `doctors.html` — What you can explore
- `about.html` — Project at a glance
- `contact.html` — Important project information

Their content and meaning are retained. Doctors now uses the same numbered presentation as the other two strips. Home's three pathway cards remain their own interactive card pattern; they are not repurposed as non-interactive signal items.

## Asset cache references

- `index.html`: `components.css?v=2`, `home.css?v=3`
- `doctors.html`: `components.css?v=2`, `doctors.css?v=7`
- `about.html`: `components.css?v=2`, `about.css?v=4`
- `contact.html`: `components.css?v=2`, `contact.css?v=4`

## Focused browser validation

Runner: `D:\\dev-toolkit\\scripts\\healthbridge-home-demo-signal-component-audit.mjs`  
Raw report: `C:\\xampp85\\migration-backups\\healthbridge\\home-demo-entry-signal-component-20261121\\home-demo-entry-signal-component-results.json`

**Result: PASS**
- Home invitation assertions: **13/13**, including both CTA destinations, unchanged directory link and header registration action, exact supporting copy, removal of “approved”, and unchanged closing heading.
- Responsive/theme states: **24** (Home, Doctors, About and Contact × dark/light × 1440, 390 and 320px).
- Shared strip typography/layout: identical across Doctors/About/Contact in both themes (**6/6 route-theme combinations**).
- The longer “Enter the demo — no signup needed” button fits at 320px, 390px and 1440px.
- Horizontal overflow: **0** cases.
- Browser page/console errors, failed requests and local HTTP errors: **0**.
- Non-GET API attempts: **0**. The audit intercepted mutating API calls; none were attempted.

This is a focused page/component check, not a full Milestone 3 regression or manual keyboard/assistive-technology audit.

## Preserved boundaries and backups

- No layout redesign, spacing system, colour palette or footer changes were made to the Home page beyond placing the requested demo line under its existing hero actions.
- No Services page or dashboard was changed.
- No PHP/API, database/schema/data, authentication/session/CSRF/RBAC, booking, worker, SMTP or hosted service was changed.
- No dependency was added. No Git staging, commit, push or deployment occurred.
- Source files were copied before edits, with SHA-256 hashes, to:
  `C:\\xampp85\\migration-backups\\healthbridge\\home-demo-entry-signal-component-20261121\\`
- Public V2 documentation and the local working handoff were updated.

## Next project gate

About remains paused for Youssef's visual review. Services is next only after About is approved; dashboards remain last.
