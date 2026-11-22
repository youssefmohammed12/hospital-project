# HealthBridge Milestone 3 — Login & Registration Owner-Review Follow-up

**Date:** 21 November 2026  
**Routes:** `pages/auth/login.html`, `pages/auth/register.html`  
**Status:** Applied; final focused responsive, interaction, accessibility, PHP-policy and local performance checks PASS. Owner visual review remains pending.

## Review items completed

### Registration footer and password guidance

Registration now uses the same canonical shared footer as Login. It loads the shared `footer.css`, `footer.js`, and the trusted `includes/fragments/footer.html` fragment. The shared fragment provides EXPLORE, DEPARTMENTS, YOUR ACCESS and the `© 2026 HealthBridge` bottom line; no second, page-specific footer was created.

The Password field now has an always-visible, screen-reader-associated hint. It reflects the implementation's actual four-of-five policy:

1. At least 8 characters.
2. An uppercase ASCII letter (A–Z).
3. A lowercase ASCII letter (a–z).
4. A digit (0–9).
5. At least one character outside A–Z, a–z and 0–9 (for example punctuation or a space).

The hint explicitly states that any four of those five checks are required. The policy in PHP/JavaScript was not changed. When the password fails, the client-side field error now names the unmet checks rather than showing the generic sentence. The text is generated from the same five check results used by the client validator.

A direct PHP validator probe passed: a password meeting exactly four checks is valid, one meeting only three is invalid, and one meeting all five is valid. The browser interaction test also verified that a 3/5 password reports only its two unmet checks and does not submit; a 4/5 password proceeds to the next wizard step using a mocked response.

### Register heading offset and both headlines

Both left-panel headlines now use the Public V2 display serif with an unchanged wording and a gold italic `<em>` accent:

- “Better connected, by design.”
- “Create your patient account”

On Register at widths from 761px upward, the heading uses a 94px sticky offset, leaving 20px below the 74px fixed header. A dark-green opaque backing keeps the pinned heading visually legible as the story content scrolls behind it. The stacked phone layout retains its natural non-sticky heading.

Measured at a 400px scroll position, the heading began at 94px while the fixed header ended at 74px at 768px, 1024px and 1440px. No screenshot was created.

### Login demo access

The demo box now explains the actual shortcut behavior: choosing a role fills that role's demo credentials and automatically starts sign-in. Buttons are ordered Patient, Doctor, Admin. The existing handler and demo account identities are unchanged.

### Four-second diagram loop

The existing connected-role diagram now has a small filled gold signal traveling down its vertical connector and 2px into the ONE CONNECTED SYSTEM bar. It loops on a 4-second cycle on both Login and Register, since the diagram and signal are a shared component.

The loop is CSS-only and uses only `transform` and `opacity`. No JavaScript timer/loop, observer, dependency, image, font or additional asset was added. `prefers-reduced-motion: reduce` removes the loop and leaves the diagram static.

## Header/footer decision

The minimal auth header (brand, theme control, Back to Home / Sign In links) is intentionally retained. It remains a valid auth-page pattern. Adding the canonical shared footer to Register restores the shared navigation and makes the two entry routes consistent without importing the full public navigation into an account flow.

## Final focused QA

Runner: `D:\dev-toolkit\scripts\healthbridge-auth-entry-review-followup-audit-20261121.mjs`  
Evidence: `C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\qa\review-followup-audit.json`

- **259/259 assertions PASS**; zero failed.
- **16 responsive/theme states**: Login and Register × 320/390/768/1440px × dark/light.
- **19 axe WCAG 2.0/2.1 A/AA scans**: 16 default route states plus Login recovery, Login verification-resend and Registration Step 2; zero violations.
- Footer, group labels, copyright, serif/gold headline, four-second loop and the registration password hint verified in every relevant responsive/theme state.
- Register's 94px sticky heading offset verified at 768/1024/1440 widths and a 400px scroll position.
- Login demo role order and auto-submit explanation verified across route states.
- Password error naming, invalid-password no-submit, four-of-five acceptance, password checklist, mocked wizard progression and return navigation all passed.
- Existing Login password reveal/hide, mocked demo shortcut, recovery open/back, verification-resend panel and theme toggle all passed.
- Reduced-motion checks on both routes found zero pending/running animations; content remains fully visible and static.
- The responsive/theme matrix recorded zero horizontal overflow, browser/console errors, failed requests, external requests or POST requests.

The test used intercepted responses for the Login shortcut and Registration Step 1. It did not sign in to an account, create an account, submit a real registration or exercise password-reset/verification email delivery.

## Local performance check

Runner: `D:\dev-toolkit\scripts\healthbridge-auth-entry-review-followup-performance-v3-20261121.mjs`  
Evidence: `C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\qa\after-performance-v3.json`

Controlled local Chromium/XAMPP measurements, no throttling; these are synthetic lab observations, not field Core Web Vitals.

| Route / viewport | FCP / LCP | CLS | Requests | Transfer |
|---|---:|---:|---:|---:|
| Login / 1440px | 320 / 320ms | 0 | 6 | 125.8KB |
| Login / 390px | 228 / 228ms | 0.0007 | 6 | 125.8KB |
| Register / 1440px | 244 / 244ms | 0.0002 | 7 | 126.8KB |
| Register / 390px | 304 / 304ms | 0.0011 | 7 | 126.8KB |

All four cases recorded zero external requests, page errors, console errors and failed requests. The Login route remains at six requests. Register now uses three additional same-origin footer resources compared with its first premium-refresh build; that is the expected cost of restoring the shared footer. Against the original pre-refresh baseline, transfer remains lower by about **63.1% on Login** and **61.5% on Register**, including the new footer.

## Changed application files and preserved behavior

- `pages/auth/login.html`: serif headline markup, demo explanation/order, auth stylesheet cache reference.
- `pages/auth/register.html`: serif headline markup, canonical shared footer, exact password hint and `aria-describedby`, auth stylesheet cache reference.
- `assets/css/pages/auth-entry.css`: serif/gold headline language, Register sticky-heading treatment, 4-second diagram loop, hint/demo copy styling.
- `assets/js/core/auth.js`: Step 1 password error now names the unmet checks. Existing password rule calculation and four-of-five threshold are unchanged.

Both entry routes now load `auth-entry.css?v=3` and `auth.js?v=9`. Existing form IDs/names, request payloads, API routes, demo account handler, registration steps, location loading, session behavior, redirects and verification-first completion remain unchanged. No PHP/API, database/schema/data, auth/session/CSRF/RBAC, SMTP, `.env`, dashboard or other page source was changed. No dependency was introduced.

The original auth-entry task disclosed one early exploratory Patient demo click that initiated a local Login POST before the automatic-submit behavior had been identified and mocked. Its response was not retained; the existing login rate limiter may have recorded an HMAC-keyed attempt in host temp. That path does not update account/profile data, and the isolated browser context was closed. The follow-up QA makes no unmocked auth POST.

## Backup and handoff

Pre-follow-up copies and hash manifest:

`C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\source-before\`  
`C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\source-before-manifest.json`

Final browser/performance evidence:

`C:\xampp85\migration-backups\healthbridge\auth-entry-review-followup-20261121\qa\`

No screenshots were created or saved. No Git stage, commit, push or deploy was performed.

**Next gate:** Owner visual review of Login and Register after this follow-up, including both themes and the two Register steps at phone width.


## Layout correction — heading scroll and footer end gap — 21 November 2026

- Removed the Register desktop sticky-heading rule and its opaque pseudo-element. The headline now stays in normal document flow and scrolls away with the story panel at all widths.
- Traced the pale 29px strip after the footer to the native Back-to-top button appended by existing `assets/js/core/main.js`. The auth-entry pages do not load its legacy styling, so it remained a static native button after the footer. The auth-owned stylesheet now hides this unused control on Login and Register; shared JS is untouched.
- Both routes now reference `auth-entry.css?v=4`.
- QA: 184/184 assertions across 20 route/width/theme states; 20 axe A/AA scans, zero violations. Footer bottom equals document bottom within 0.44px subpixel rounding; heading naturally exits the viewport at scrollY=400 on 768/1024/1440. Zero overflow/browser/network/external/POST errors.
- Full result: `docs/HEALTHBRIDGE_MILESTONE_3_AUTH_ENTRY_REGISTER_FOOTER_LAYOUT_FIX_20261121.md`; evidence under `C:\xampp85\migration-backups\healthbridge\auth-entry-register-footer-layout-fix-20261121\qa\`. No screenshots, stage, commit, push or deploy.
