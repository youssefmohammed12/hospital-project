# HealthBridge — Doctors performance and selector polish

**Date:** 20 November 2026  
**Scope:** Doctors directory only  
**Route:** `http://127.0.0.1/optimized/doctors.html`  
**Status:** Changes implemented; awaiting owner review. Post-change Lighthouse score still unverified.

## Changes applied

- Added `defer` to the existing bottom-of-page `main.js` and `doctors.js` script tags. Their order is unchanged: shared runtime first, Doctors controller second.
- Kept `assets/css/pages/settings.css?v=2` in place. Although guest-mode Lighthouse coverage reports it as unused, the shared navbar dynamically loads the Settings modal for signed-in users; that modal depends on this stylesheet. Removing it would degrade a real authenticated interaction. Conditional loading is a future shared-runtime optimization, not part of this page-only pass.
- Kept the Doctors CSS page-scoped and bumped its URL from `doctors.css?v=6` to `doctors.css?v=7`.
- Replaced only the sort selector's native arrow with a small CSS chevron. The chevron sits 13px inward from the right edge, with separate dark/light colors. Existing selector width, 52px height, right padding, and `#sort-filter` hook are preserved.

## Verification

- Route returned HTTP 200; the API-backed directory settled at 32 profiles.
- All three directory API calls returned HTTP 200 using GET only.
- Keyboard ArrowDown changed the selected option; alphabetical sorting remained functional.
- Checked 320, 390, 768, 1024 and 1440px in light and dark themes. The select kept the same 52px height and responsive layout width at every size; its chevron remained 13px from the right edge. No horizontal overflow.
- The expected title, copyright and footer scope copy were confirmed in a separate settled-page check.
- The repeated full audit recorded 14 axe scans with zero violation instances, no interaction failures, and passing motion/reduced-motion checks. Its summary did not go fully green because it intermittently sampled the early fallback card set (8 rather than 32) and flagged static content assertions that a subsequent settled-page check confirmed. Correct the runner's readiness wait before treating its summary as a release gate.
- Focused browser check: no page exceptions, non-CSP console errors or failed requests.
- `git diff --check -- doctors.html assets/css/pages/doctors.css` passed.

## Performance findings and remaining opportunities

The previous Lighthouse 13.6 baseline remains mobile Performance 86 and desktop 98. These are pre-polish scores, not results after adding `defer`. A post-change CLI run ended with `NO_FCP` in the remote headless environment, so no performance-score improvement is claimed.

The previous mobile trace identified:
- approximately 1.82s of potential render-blocking savings;
- the Doctors page controller among the render-blocking requests;
- the hero intro paragraph as the LCP element, with about 931ms element-render delay;
- roughly 43 KB of unused rules in shared `style.css`, 14 KB in `doctors.css`, and 99.7% unused Font Awesome CSS on the guest directory view;
- most main-thread time in style/layout rather than JS execution. TBT was already 0ms and the page has no large image payload to optimize.

The current changes address script parser-blocking behavior without altering shared code. They do not eliminate the critical CSS cost, the Sora import, or the Font Awesome request. Further work should be sequenced carefully:

1. **Shared CSS reduction:** manually identify the truly required shared navbar, button, toast and theme rules before extracting a lean public-page shell. Do not purge or rewrite `style.css` blindly; dashboards also use it, and dynamic JS markup must be included in the selector inventory.
2. **Conditional Settings CSS:** load `settings.css` only when the signed-in Settings feature is available, or on demand before its modal opens. This needs a planned shared-runtime change and an authenticated regression test.
3. **Font Awesome:** replace the shared navbar/toast icon use with local SVGs or a minimal local icon treatment before removing the stylesheet. It is not safe to remove it from the Doctors shell while those shared states still rely on it.
4. **Legacy Sora import:** `style.css` still attempts to import Sora, although Doctors uses a local system font stack and the current CSP blocks the remote Google Fonts request. Address this in the shared stylesheet cleanup, not with an isolated global edit.
5. **HTTP compression:** local Apache currently serves CSS/JS without a `Content-Encoding` response, and `mod_deflate` is disabled in XAMPP Apache configuration. Enabling it could reduce transfer size across local apps, but requires a machine-level Apache configuration change and restart; it was not changed here.

Keep the `noindex,nofollow,noarchive` policy unchanged; do not trade indexing policy for a Lighthouse SEO score.

## Files changed

- `doctors.html`
- `assets/css/pages/doctors.css`
- `docs/HEALTHBRIDGE_MILESTONE_3_DOCTORS_PERFORMANCE_POLISH_20261120.md` (this report)

No shared stylesheet/controller, PHP, API, database, auth/session, RBAC, dashboard, dependency, or Apache configuration was changed. No Git staging, commit, push, deployment or history operation was performed.

Pre-edit copies:
`C:\xampp85\migration-backups\healthbridge\milestone3-doctors-performance-polish-20261120\source-before\`

SHA-256 after this follow-up:
- `doctors.html`: `9082FE08160E14E8E20AAFEA129DFDCC8D2513358ACBEC881F1C92B43E67FCCF`
- `assets/css/pages/doctors.css`: `F5FCE230D9BAFC8B45B05E4C1F346380F7780E45ADABFB07012CBEC156D4EF49`

Evidence:
`C:\xampp85\migration-backups\healthbridge\milestone3-doctors-performance-polish-20261120\`
