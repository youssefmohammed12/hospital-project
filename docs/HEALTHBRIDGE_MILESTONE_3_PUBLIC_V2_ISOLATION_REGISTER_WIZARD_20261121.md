# HealthBridge Milestone 3 — Public V2 isolation and Register wizard refinement

Date: 21 November 2026  
Status: Implemented locally; focused browser verification PASS. Owner visual review remains open.

## Objective and acceptance boundary

The seven pages already redesigned in Milestone 3 must be independent of the legacy frontend implementation. A migrated page may reuse the Public V2 foundation and other Public V2 page modules, but it must not load or rely on the legacy global stylesheet, page stylesheets, main.js, auth.js, or legacy page controllers.

The existing PHP backend, API endpoints, request field names, authentication/session behavior, verification-first registration process, CSRF/RBAC/security rules, database and business logic remain unchanged.

## Pages audited

- index.html — Home
- about.html — About
- services.html — Services
- contact.html — Contact
- doctors.html — Doctors
- pages/auth/login.html — Login
- pages/auth/register.html — Register

### Isolation changes

The five main public pages were already loading Public V2 assets only. Their shared runtime reference was advanced to site.js?v=2, and all seven migrated pages now use components.css?v=3 to receive the corrected shared reveal behavior.

Login and Register were the two exceptions discovered during the audit. They previously loaded legacy assets/js/core/main.js, legacy assets/js/core/auth.js and assets/css/pages/auth-entry.css. Those connections have been removed.

Both auth entry pages now load:
- Public V2 tokens, base reset, components and canonical footer styles.
- Public V2 site.js runtime and footer.js.
- New page-owned assets/public-v2/css/pages/auth.css.
- New page-owned assets/public-v2/js/pages/auth-entry.js.

The V2 runtime now owns the auth entry pages' theme toggle. Auth page code owns its password visibility controls and auth-specific safe redirect/storage helpers. No legacy global script is required to initialize the auth page.

The old page-owned assets/css/pages/auth-entry.css file was moved into the V2 page asset area and deleted from its old location after the page references were changed. Legacy main.js and auth.js themselves were not deleted or modified: they remain necessary for application routes that have not yet been migrated.

## Registration wizard refinement

The previous second step combined identity/address, emergency-contact, medical and insurance fields in one long form. Register now guides the user through three focused stages:

1. **Account** — credentials and contact details. The existing step: 1 validation request is retained.
2. **Patient profile** — date of birth, gender, optional blood type, National ID, governorate, city and address. This stage validates client-side and retains values in page memory.
3. **Emergency contact** — required emergency contact details. Optional medical and insurance information is grouped in a collapsed-by-default disclosure panel.

On phone widths, paired fields use a compact two-column layout where appropriate; full-width fields such as National ID and address remain full width. Navigation keeps entered values, updates the progress indicator and page heading, and brings the active stage into view. Reduced-motion preferences are respected.

The final registration request is still the existing step: 2 POST to api/auth/register.php. Its original field names and combined payload shape are preserved. No additional endpoint, database field, server validation rule or registration side effect was introduced.

The previous browser measurements at 390px were approximately 906px for the combined profile/contact step and 606px for its collapsed content. With the new layout, the Patient profile step is approximately 690px and the collapsed Emergency contact step approximately 490px — about 24% and 19% shorter respectively. At 320px the corresponding measurements are approximately 707px and 509px. Optional-field expansion is still possible when needed, but it no longer dictates the default page length.

## Additional shared responsive correction

The seven-page audit exposed an existing V2 reveal-style cascade issue: the narrow-screen rule that changed left/right reveals into vertical motion was declared before the generic reveal rules, so it was overridden. Off-screen cards retained a 28px horizontal transform and expanded document scroll width on phones and compact tablets.

The winning responsive override now follows the generic rules in assets/public-v2/css/components.css. Up to 900px, directional reveals use the vertical reveal animation. The motion remains; the page no longer gains horizontal overflow from unrevealed elements. All seven page references to the shared components stylesheet were cache-bumped to v=3.

## Verification

Focused runner:
D:\dev-toolkit\scripts\healthbridge-public-v2-auth-isolation-register-three-step-audit-20261121.mjs

Raw result:
C:\xampp85\migration-backups\healthbridge\public-v2-auth-isolation-register-wizard-20261121\qa\public-v2-isolation-audit.json

Final result:
- **535/535 assertions PASS** across 39 route/viewport/theme states.
- **16 axe WCAG 2.0/2.1 A/AA scans; zero violations.**
- All seven migrated pages render without horizontal overflow at 320, 390, 768, 1024 and 1440px in the dark theme. Login and Register also receive extra light-theme checks at 390 and 1440px.
- All seven pages resolve to Public V2 CSS/JS assets only; no legacy back-to-top element is injected.
- Four complete mocked Register flows pass at 320, 390, 768 and 1440px. Both registration POST stages are intercepted. The final request's original key set and combined values are asserted. No real registration or database write occurred.
- Login required-field validation, recovery open/back, password reveal/conceal, and Public V2 theme toggle pass. Login auth requests are intercepted; no real sign-in occurs.
- Zero external requests, failed network requests, unmocked page-load POSTs, JavaScript exceptions or unexpected console errors.
- Services performs the existing same-origin current_user.php GET when no cached identity exists. Five HTTP 401 responses are recorded as the expected unauthenticated guest-session result, preserving server-session detection for a user who does have a valid session. These are not treated as JavaScript failures.
- No screenshots were created or saved.

A separate static source scan also passed:
- Zero legacy frontend asset references in the seven migrated HTML files.
- Zero legacy frontend path references in executable CSS/JS under assets/public-v2.
- The old assets/css/pages/auth-entry.css path no longer exists.

## Scope not changed

pages/auth/reset-password.html and pages/auth/verify-email.html were not among the seven pages redesigned for Public V2 in this task. They still use legacy presentation assets and their existing page-specific controllers; they remain unmodified and must receive their own Public V2 migration before they can be considered isolated. Other non-migrated portal/dashboard pages likewise retain their legacy frontend until their respective migration group.

No PHP/API, auth/session/CSRF/RBAC, DB/schema/data, SMTP, worker, hosted service, secret or unrelated project file was changed. No Git stage, commit, push or deployment was performed.

## Rollback and handoff

Pre-edit copies and SHA-256 manifest:
C:\xampp85\migration-backups\healthbridge\public-v2-auth-isolation-register-wizard-20261121\source-before\

Final source SHA-256 manifest:
C:\xampp85\migration-backups\healthbridge\public-v2-auth-isolation-register-wizard-20261121\final-source-manifest.json

The detailed route/flow evidence is retained in the QA JSON above. Manual owner visual review remains open, especially Register at 320/390px in both themes.

## Additional Login behavior smoke test

Runner: D:\dev-toolkit\scripts\healthbridge-public-v2-auth-entry-smoke-20261121.mjs  
Evidence: C:\xampp85\migration-backups\healthbridge\public-v2-auth-isolation-register-wizard-20261121\qa\auth-entry-v2-functionality-smoke.json

**13/13 assertions PASS.** Confirmed Patient demo shortcut fills credentials and invokes the existing login endpoint; verification-resend panel opens, copies the login email and retains its existing endpoint/payload; forgot-password panel opens and retains its original endpoint/payload; same-origin reset URL handling works. All three requests were intercepted/mocked. No real sign-in, email send or account change occurred. No JavaScript exceptions, unexpected console errors or screenshots. The V2 controller header now correctly identifies the three-stage registration wizard.

