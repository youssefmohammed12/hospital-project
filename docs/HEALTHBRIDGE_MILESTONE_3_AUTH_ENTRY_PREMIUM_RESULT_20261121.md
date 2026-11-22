# HealthBridge Milestone 3 — Login and Registration Entry Refresh

**Date:** 21 November 2026  
**Routes:** `pages/auth/login.html`, `pages/auth/register.html`  
**Status:** Applied; focused responsive, interaction, accessibility and local performance checks PASS; awaiting owner visual review

## Design direction

The two entry screens now use a dedicated HealthBridge account experience: a deep-green editorial brand panel, a connected-workspaces diagram, four compact feature cues, and a quieter account form surface with warm brass accents. The panel, form controls, demo account shortcuts, registration progress and success states share one consistent visual language. Light and dark themes retain the same composition with appropriate surface and text tokens.

The existing content and user journeys remain recognizable. Login still supports demo role shortcuts, remember-email, password reveal, password recovery and verification resend. Registration remains patient-only and uses the existing two-step account/profile sequence, validation, password checklist, governorate/city loading, and email-verification-first completion.

## Motion system

Motion is deliberately short and finite rather than ambient:

- The main auth frame enters once (680ms) with a small opacity/translate/scale settle.
- Brand content and its four information cues arrive in a short stagger.
- The three-role connection diagram sends one small gold signal down its connector (920ms, once).
- Registration step changes and the email-confirmation success state use a 420–430ms opacity/translate transition.
- The button spinner animates only while an existing submission is in progress; no decorative loop runs at rest.

All choreography uses CSS transform/opacity. No JavaScript animation loop, timer, observer, new package, image or remote animation resource was added. `prefers-reduced-motion: reduce` removes the entrance, signal and step animations while keeping every panel visible and usable.

## Performance results

Controlled local lab comparison using Chromium headless, fresh context per route/viewport, no CPU/network throttling, on the local XAMPP site. These are synthetic lab measurements, **not field Core Web Vitals**.

| Route / viewport | FCP before → after | LCP before → after | Transfer before → after | Requests before → after |
|---|---:|---:|---:|---:|
| Login / 1440px | 684 → 224ms | 684 → 224ms | 340.9 → 124.0KB | 10 → 6 |
| Login / 390px | 492 → 172ms | 588 → 172ms | 340.9 → 124.0KB | 10 → 6 |
| Registration / 1440px | 852 → 276ms | 920 → 276ms | 329.6 → 112.8KB | 8 → 4 |
| Registration / 390px | 584 → 196ms | 652 → 196ms | 329.6 → 112.8KB | 8 → 4 |

The page transfers are lower by about **216.8–216.9KB per route (63.6–65.8%)** in these runs. Three prior external requests per page are now zero: the Google-hosted Sora stylesheet and Font Awesome stylesheet/font are no longer required. The new page-owned stylesheet uses a system font stack and the small interface marks are inline SVG. No replacement remote font, icon font or asset was introduced.

CLS remained 0 on desktop; mobile samples measured 0.0007 (login) and 0.0011 (registration). The one desktop login long task was also present in the before run; no new long-task pattern appeared in the other cases. Local results are useful for comparison under these conditions but should not be treated as field-user guarantees.

## Focused QA

Runner: `D:\dev-toolkit\scripts\healthbridge-auth-entry-design-audit-20261121.mjs`  
Evidence: `C:\xampp85\migration-backups\healthbridge\auth-entry-design-20261121\qa\auth-entry-design-audit.json`

- **184/184 assertions PASS** across 16 route/viewport/theme combinations: Login + Registration × 320/390/768/1440px × dark/light.
- **19 axe WCAG 2.0/2.1 A/AA scans; zero violations.** Sixteen default page states plus Login recovery, Login verification-resend and Registration Step 2.
- Zero horizontal document overflow, browser page errors, console errors or failed requests in the responsive/theme matrix.
- Login password reveal/hide, demo shortcut, recovery open/back, verification-resend panel and theme toggle passed.
- Registration password checklist, mocked Step 1 transition, one-shot Step 2 animation and return-to-Step 1 passed.
- Reduced-motion behavior passed on both routes: no pending/running entry animations; content remains fully visible and static.
- No external requests occurred in the final responsive/theme matrix. Registration's wizard interaction used a local Playwright-intercepted response, not a real registration.
- The login demo shortcut is intentionally auto-submit behavior in the existing controller. The final interaction test intercepts `api/auth/login.php` and returns a test-only 401, proving the shortcut wiring without submitting credentials to the actual backend.

Performance runners:
- Before: `D:\dev-toolkit\scripts\healthbridge-auth-entry-before-performance-20261121.mjs`, evidence `qa\before-performance.json`.
- After: `D:\dev-toolkit\scripts\healthbridge-auth-entry-performance-baseline-20261121.mjs`, evidence `qa\after-performance.json`.

### Test-probe disclosure

One initial exploratory UI run clicked the Patient demo shortcut before its automatic-submit behavior had been identified and before the endpoint was mocked. It initiated one local POST to the existing login endpoint; that exploratory run did not retain the response, so its acceptance outcome cannot be confirmed. The login handler's rate limiter stores HMAC-keyed attempt state under the host temporary directory; that counter may have recorded the probe. The handler's login path does not update account/profile data, and the isolated Playwright browser context was closed. No further unmocked auth POST was made: the final test intercepts the login request, and the registration test intercepts its Step 1 request. No real registration, password-reset, verification-resend or email flow was exercised.

## Changed application files and preserved contracts

Application scope for this task:

- `pages/auth/login.html`
- `pages/auth/register.html`
- New `assets/css/pages/auth-entry.css`

Both entry routes now load `auth-entry.css?v=1` instead of the legacy global `style.css`, previous shared `auth.css`, and the external Font Awesome stylesheet. Login keeps the shared footer. The reset-password and verify-email routes still use `assets/css/pages/auth.css?v=6`; that stylesheet was not changed.

Existing `main.js?v=7` and `auth.js?v=8` references, form IDs and names, API URLs, request payloads, demo-account controller, validation, session assumptions, registration workflow, email verification behavior and redirects were preserved. No auth JavaScript, PHP/API, database/schema/data, SMTP, .env, security/session/RBAC, dashboard, other route or shared component source was edited for this redesign. No dependency was added.

## Backup and handoff

Verified pre-edit HTML copies and SHA-256 manifest:

`C:\xampp85\migration-backups\healthbridge\auth-entry-design-20261121\source-before\`  
`C:\xampp85\migration-backups\healthbridge\auth-entry-design-20261121\source-before-manifest.json`

The final browser evidence is stored under:

`C:\xampp85\migration-backups\healthbridge\auth-entry-design-20261121\qa\`

No screenshots were created or saved. No Git stage, commit, push or deploy was performed.

**Next gate:** Owner visual review of Login and Registration in both themes, including the two registration steps at phone width.


## Owner-review follow-up — 21 November 2026

This follow-up supersedes the original first-build notes for the entry pages where they differ. The current styles are `auth-entry.css?v=3`, and both entry routes now load `auth.js?v=9`.

- Register now loads the same canonical shared footer as Login, including Explore, Departments, Your access and © 2026 HealthBridge.
- Both story-panel headlines now use the Public V2 serif display face with a gold italic accent; wording is unchanged.
- Register now states the exact four-of-five password policy under Password. Invalid-password errors name only the checks the submitted password misses. The existing server/client rule calculation and threshold are unchanged.
- On Register at widths ≥761px, the story-panel heading pins at 94px below the fixed 74px header, with a dark backing to remain legible. Phone layout remains naturally stacked.
- Login now explains that a role button fills demo credentials and automatically starts sign-in; roles are ordered Patient, Doctor, Admin.
- The shared role diagram now sends a small CSS-only gold signal down into the hub on a 4-second loop. Reduced-motion keeps it static.
- Follow-up QA: 259/259 assertions; 19 axe WCAG A/AA scans, zero violations; sticky heading geometry checked at 768/1024/1440; PHP password validator examples (3/5 invalid, 4/5 valid, 5/5 valid) passed. All auth POSTs in this follow-up browser test were intercepted; no account was created or real sign-in performed.
- Final follow-up performance evidence: `C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\qa\after-performance-v3.json`. Transfer remains ~63.1% lower on Login and ~61.5% lower on Register than the original pre-refresh lab baseline, including the restored shared footer.

Detailed follow-up report: `docs/HEALTHBRIDGE_MILESTONE_3_AUTH_ENTRY_OWNER_REVIEW_FOLLOWUP_20261121.md`. Backup, runner and raw evidence are under `C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\`. No screenshots, stage, commit, push or deploy.
