# HealthBridge — Auth Consistency: Four Owner Jobs

**Date:** 21 November 2026  
**Scope:** Login/Register animation consistency, auth back-arrow encoding, shared brand mark, and borderless auth brand block.  
**Status:** Implemented and locally verified.

## Job A — Applicable Register animations on Login

Login now uses the same two-layer aurora as Register inside its left story panel. The gold glow runs on the existing 20-second transform keyframe and the teal glow on the 22-second keyframe. Both use the same pre-blurred radial-gradient layers (blur(34px)) and low-opacity color treatment. The blur/filter itself remains static; only transform animates. No additional timer, JavaScript loop, image or dependency was introduced.

The Register focus-underline rules are now shared by the .auth-entry-page family. Login text/email/password inputs receive the same 200ms gold underline on focus; the same shared selectors cover selects and textareas on auth pages that contain them. This was checked on Register's select and textarea controls because the Login form currently has no select or textarea fields.

The Login #login-submit (“Sign In”) button now uses the same clipped five-second transform-only shine. The shine stops for hover, active/pressed and disabled states.

The patient preview card and wizard/step animations remain Register-only. No patient-card or stepper markup was added to Login.

**Reduced motion:** The Login aurora is hidden, button shine is disabled, and focus underlines remain static without a draw animation. The existing Register behavior is retained.

## Job B — UTF-8 back arrows

Both auth HTML files retain their UTF-8 BOM and declare <meta charset="UTF-8" />. The visible “Back to Home” labels now use the literal left-arrow character (←) on both Login and Register. The secondary Login “Back to Login” button had the same malformed arrow sequence and was corrected as part of the same encoding repair. The page-title mojibake around the em dash was also corrected on both auth pages.

No other page used the “Back to Home” snippet.

## Job C — One HealthBridge building mark

The approved SVG path is now used for every logo/brand slot across the seven migrated pages:

- Public page headers on Home, About, Services, Contact and Doctors.
- The additional About project/product preview mark.
- The shared footer brand mark rendered by the canonical footer fragment.
- Login and Register navbar logos and auth-panel brand blocks.
- The Register patient preview-card mark.
- The browser favicon uses the same building path inside its existing square favicon treatment.

The same path data is used throughout; the old CSS-generated plus marks for the Public V2 header/footer were removed. The shared footer fragment and all seven migrated routes received cache-version updates so visitors do not retain the old mark.

reset-password.html and verify-email.html remain non-migrated legacy routes. They were not changed; they will receive the approved mark when those routes are migrated, as requested.

## Job D — Borderless auth brand block

The auth left-panel content and wordmark are now explicitly borderless with no box shadow. The logo tile inside .auth-brand-logo also has no border, background tile or shadow. The navbar's separate logo treatment is unchanged. Computed-style checks passed in both dark and light themes on Login and Register.

## Files changed

- index.html, about.html, services.html, contact.html, doctors.html
- pages/auth/login.html, pages/auth/register.html
- includes/fragments/footer.html, favicon.svg
- assets/public-v2/css/components.css
- assets/public-v2/css/pages/about.css, assets/public-v2/css/pages/auth.css
- assets/public-v2/css/footer.css
- assets/public-v2/js/core/footer.js (shared-footer fragment cache version only)

Cache updates:
- Shared components CSS v=3 → v=4
- Shared footer CSS v=1 → v=2
- Shared footer JS v=1 → v=2
- Shared footer fragment v=1 → v=2
- About CSS v=6 → v=7
- Auth CSS v=4 → v=5
- Favicon URL cache key v=2

No PHP, auth controller, API endpoint, database, session, CSRF, authorization or business logic was changed.

## Verification

**Browser audit: PASS — 121/121 assertions.**

- All seven migrated pages returned HTTP 200 and rendered the canonical footer with the exact approved SVG.
- All visible page-specific logo slots, including the extra About and Register marks, matched the approved path exactly.
- Desktop checks plus a 390px mobile sweep passed with no horizontal overflow.
- Login aurora confirmed at 20s gold / 22s teal, with static blur(34px) while transforms changed.
- Login email underline confirmed at 200ms; shared Register select and textarea underlines also confirmed at 200ms.
- Sign In shine confirmed at 5s and paused on hover, press and disabled.
- Reduced-motion Login test confirmed hidden aurora, disabled shine and static focus underline.
- Auth brand content/logo computed styles were borderless in both themes.
- Login and Register source/runtime checks found no legacy frontend assets.
- Ten axe WCAG 2.0/2.1 A/AA scans passed with zero violations.
- Zero page exceptions, unexpected console errors, failed requests, missing resources, external requests or POST requests. One expected anonymous GET /api/auth/current_user.php returned HTTP 401 during the public Services guest-session check; no authentication submission or state mutation occurred.
- Static source audit: 48/48 checks passed, including UTF-8 BOM/charset, arrow characters, every logo path, favicon, shared selectors and reduced-motion-related declarations.
- node --check passed for the browser audit runner and shared footer JavaScript. Scoped git diff --check passed; Git emitted only the repository's usual LF-to-CRLF normalization warnings.
- No screenshots were created or saved. No real login, registration or backend write was performed.

**Raw evidence:**  
C:\xampp85\migration-backups\healthbridge\auth-consistency-20261121\qa\auth-consistency-audit.json

**Source rollback copies and hash manifests:**  
C:\xampp85\migration-backups\healthbridge\auth-consistency-20261121\source-before\  
C:\xampp85\migration-backups\healthbridge\auth-consistency-20261121\source-before-manifest.json  
C:\xampp85\migration-backups\healthbridge\auth-consistency-20261121\final-source-manifest.json

**Runners:**  
D:\dev-toolkit\scripts\healthbridge-auth-consistency-audit-20261121.mjs  
D:\dev-toolkit\scripts\healthbridge-auth-consistency-static-audit-20261121.cjs

No stage, commit, push or deployment was performed.
