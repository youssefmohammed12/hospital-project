# HealthBridge M3.5 — Public entry and authentication migration result

**Date:** 20 November 2026  
**Status:** PASS — local public-entry and authentication automated gates  
**Scope:** Homepage/demo entry journey, login, two-step registration, password recovery/reset, verification-token states and shared Sign Up destinations. This slice preserves the existing PHP endpoints and browser-side auth/booking contracts.

## 1. Authentication surfaces

Integrated routes:
- pages/auth/login.html
- pages/auth/register.html
- pages/auth/reset-password.html
- pages/auth/verify-email.html

Shared page stylesheet: assets/css/pages/auth.css. The same page-owned styling now handles login, two-step registration, recovery and resend panels, standalone reset and email verification. Shared typography, design tokens and the global shell remain in assets/css/style.css.

Final Playwright 1.65 Chromium + axe-core 4.14.0 run against the final shared main.js version:
- 4/4 routes HTTP 200.
- 44/44 WCAG 2.0/2.1 A/AA axe scans, zero violations. Coverage includes 4 pages across 320/390/768/1440 px in light/dark themes, registration Step 2, and recovery/verification-resend states.
- 32/32 route/viewport/theme layout checks without horizontal overflow.
- 27/27 scripted UI interactions passed.
- Browser page exceptions: 0. Non-CSP console errors: 0.
- POST requests: 0.

Interactions checked without submitting credentials: sign-in form/labels, recovery panel open/back, verification resend panel, demo account shortcuts presence, password reveal/hide, step 1/2 registration navigation, required fields, Egyptian mobile validation, 14-digit National ID input format, password strength/checklist, confirmation mismatch feedback, missing-token reset state and missing-token verification disabled state.

No login was executed. No account was registered, no password reset was requested/completed, no verification email was sent and no real patient or medical data was entered. Existing form IDs/names, validation contracts, auth.js, reset-password.js and verify-email.js behavior were preserved.

The replacement auth stylesheet is 28,336 bytes compared with the exact 31,894-byte pre-edit copy (11.2% smaller). Existing shared CSS tokens and page structure remain separate. Unsubstantiated claims such as HIPAA compliance were removed from the auth copy; registration now explicitly warns that only synthetic information should be used.

## 2. Homepage and public entry journey

The real homepage at index.html now presents HealthBridge as a student portfolio demonstration. The prior mock emergency phone number, fabricated clinic hours/location, insurance-provider logos and patient testimonials were removed. Copy now states that the records are synthetic and the site is not a live healthcare provider.

The homepage now:
- Directs Sign Up and patient-account calls to pages/auth/register.html.
- Keeps Sign In as the route for the existing patient appointment workflow.
- Sends doctor discovery to doctors.html.
- Replaces the fictional clinical quick action with a demo overview anchor.
- Uses the existing read-only departments endpoint to populate 9 department cards, now native keyboard-accessible links carrying the doctors.html?q=… filter.
- Routes the hero Search button with its entered query to the existing doctors-directory filter.
- Uses noindex/nofollow/noarchive and no-referrer metadata for the synthetic demonstration.

Final Playwright 1.65 Chromium + axe-core 4.14.0 run:
- Homepage route HTTP 200.
- 9 live department cards rendered from api/departments/get.php; observed read-only requests returned HTTP 200.
- 8/8 WCAG 2.0/2.1 A/AA axe scans across 320/390/768/1440 px in light/dark themes, zero violations.
- 8/8 responsive layout checks without horizontal overflow.
- 16/16 scripted interactions passed, including header/hero/footer Sign Up paths, booking sign-in path, demo-overview anchor, directory link, search query navigation, semantic department links and removal of mock claims.
- Browser page exceptions: 0. Non-CSP console errors: 0. POST requests: 0.

Homepage styles were consolidated from the exact 10,567-byte backup to 8,499 bytes (19.6% smaller). Its page controller was rewritten from 7,289 bytes to 3,873 bytes (46.9% smaller); the unused fictitious hospital-hours request and unused statistic-counter logic were removed. The live departments GET remains. The home search and department navigation keep the existing doctors.html?q=… contract.

## 3. Shared navigation routes and cache refresh

The shared navbar Sign Up action in assets/js/core/main.js and the shared layout component now point directly to the patient registration route rather than the retired login.html#signup fragment.

A repository-wide reference check found 9 files containing that old signup destination; all were backed up, hash-verified and updated to register.html. The final search found zero remaining login.html#signup references in application HTML/JavaScript.

Because main.js is shared, every HTML consumer using the v6 URL was also backed up and its query version bumped to v7. **22/22 exact pre-edit file hashes were verified**, and the final scan found zero v6 references and 22 v7 references. Cache-busting changed only the script URL version, not the page/business logic.

## 4. CSS/JS ownership and retained behavior

Page-owned auth styling remains in auth.css; public homepage composition remains in home.css; department loading and search behavior remain in home.js. The following contracts were not changed:
- PHP/API authentication endpoints, request methods/payloads, CSRF or session assumptions.
- Registration's two-step POST workflow and field IDs/names.
- Password-reset and email-verification token handling.
- Existing role/RBAC behavior and doctor-directory filter query.
- Patient booking navigation and appointment business rules.

The global stylesheet still reports CSP warnings from its legacy Sora @import and existing inline styles. Removing duplicate page-level Sora links did not remove the global import warning. The Content Security Policy was not weakened. These shared font/CSP warnings remain for a separately scoped M3.6 asset/security pass.

## 5. Evidence and rollback

Evidence folders:
- Auth source copies: C:\xampp85\migration-backups\healthbridge\milestone3-auth-20261120\source-before\
- Auth result: C:\xampp85\migration-backups\healthbridge\milestone3-auth-20261120\auth-baseline-results.json
- Public/home source copies: C:\xampp85\migration-backups\healthbridge\milestone3-public-entry-20261120\source-before\
- Homepage result: C:\xampp85\migration-backups\healthbridge\milestone3-public-entry-20261120\home-public-entry-results.json
- Shared main.js URL bump: C:\xampp85\migration-backups\healthbridge\milestone3-shared-script-cache-20261120\manifest.json (22/22 backups verified)
- Signup-route repair: C:\xampp85\migration-backups\healthbridge\milestone3-signup-route-20261120\manifest.json (9/9 backups verified)

Reproducible test sources:
- D:\dev-toolkit\scripts\healthbridge-m3-auth-baseline.mjs
- D:\dev-toolkit\scripts\healthbridge-m3-home-public-audit.mjs

Selected final source hashes:
| File | SHA-256 |
|---|---|
| index.html | 7F7E3CCF4E358E379D081B3EA0BAFA2AB28768AA6CA26541106EE97FDC5033E1 |
| assets/css/pages/home.css | C5FFCD32BD88FD21D2A17D815BF50CBDF225700520F98BF1CE729EBE48ADADD9 |
| assets/js/pages/home.js | 3E8B77842074769CF510C606CE36C29E30F8D5B003A397ED6B149F0C98A3D1ED |
| assets/js/core/main.js | 166640ADBD4BB8EA322DD2F2DD3F1826F1D5382512C3F0AB31C808B67A0CFB78 |
| assets/css/pages/auth.css | 88DA09484CB4EF82147663563EFDCF38915585F7D2AD4BF2CA363CD188946281 |
| assets/js/core/auth.js (unchanged) | 4743C37568FBB6F7ABDBED52634B82446253C212747E2CB264647E526BFCCD6F |

## 6. Explicit limitations and next action

Automated axe checks are not accessibility certification. Manual keyboard-only, zoom/reflow and assistive-technology checks remain open. The auth audit did not exercise real authenticated settings/notification behavior or any mutating auth flow. Existing CSP warnings remain.

No PHP/API/backend/auth business logic, database, worker, SMTP, .env, hosted resource or deployment was changed. No database writes, login/registration/reset submissions or real patient data were used. No Git stage/commit/push occurred.

**Next migration group:** review the remaining public informational pages (About, Services and Contact) for the same demo-truth/content contract, then proceed to the Patient portal. Keep the page-by-page backup/caller/regression procedure and preserve role/data/auth contracts.
