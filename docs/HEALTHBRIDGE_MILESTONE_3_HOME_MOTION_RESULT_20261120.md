# HealthBridge Milestone 3 — Homepage Motion Implementation Result

**Date:** 20 November 2026  
**Route:** `http://localhost/optimized/`  
**Status:** Implemented locally; automated regression passed; owner visual review remains open.  
**Scope:** Homepage only.

## Implementation

The homepage now has a restrained, page-owned motion system built with CSS keyframes and a small native JavaScript controller. No framework, animation package, shared controller, PHP endpoint, or backend behavior was changed.

### Hero entrance

The first-load sequence is ordered and plays once only when the browser is at the top of the page:

1. Eyebrow and project-scope badge.
2. Main headline.
3. Supporting copy.
4. Hero consultation visual, with a gentle image settle.
5. Supporting note.

The primary action and doctor search remain visible and usable throughout; they receive only a subtle vertical settle. The sequence is skipped after a mid-page scroll restoration and when reduced motion is requested.

### Scroll choreography

- Pathway cards enter with a small stagger.
- Capability introduction enters from the left; its individual rows enter from the right.
- Department heading and live department cards reveal as they enter the viewport.
- Project introduction and its three steps use opposite directional reveals.
- Closing invitation receives a subtle reveal.
- On narrow layouts, directional motion switches to vertical movement to avoid horizontal overflow.

The reveals are one-shot and use transform/opacity only. The existing fail-open behavior remains: content is visible by default; JavaScript applies the pending hidden state only for an active reveal. A failed script, missing IntersectionObserver, no-JavaScript environment, restored mid-page scroll, or reduced-motion preference cannot leave the homepage content hidden.

### Micro-interactions

- Navigation underline animates with transform rather than changing layout dimensions.
- Pathway cards lift slightly on hover for fine-pointer devices.
- Search receives a restrained brass focus ring.
- Primary action and search button have a subtle pressed state.
- Existing keyboard focus indicators remain visible.

## Maintainability

- Motion stays within `assets/css/pages/home.css` and `assets/js/pages/home.js`.
- No global `main.js` or `style.css` animation rules were changed.
- No third-party animation dependency was added.
- Hero choreography and scroll reveals have separate initialization paths.
- The browser-restoration guard is shared with the visibility reliability fix, so the entrance does not replay over a restored mid-page view.
- Existing search navigation, department API and markup hooks remain unchanged.

## Files changed

- `index.html` — cache versions: `home.css?v=11`, `home.js?v=8`.
- `assets/css/pages/home.css` — homepage motion keyframes, sequence, micro-interactions, narrow-screen and reduced-motion rules.
- `assets/js/pages/home.js` — homepage hero entrance lifecycle and page-owned viewport reveal controller.

No class/id/data hook used by existing search or department behavior was renamed. No backend, PHP, API, database, authentication, session, authorization, dashboard, shared JavaScript or shared CSS files were changed in this step.

## Automated verification

Final result: **PASS**

| Check | Result |
|---|---:|
| Homepage route | HTTP 200 |
| Live departments | 9 cards; API HTTP 200 |
| Hero sequence | Passed; class cleaned up after entrance |
| Controls during hero entrance | Remain visible and usable |
| Section reveal variants | 8/8 passed |
| Pathway hover and button press | Passed |
| Search focus and nav underline | Passed |
| Native mid-page Chromium refresh | Passed; intro and all three capability rows visible |
| Reduced-motion behavior | Passed |
| No-JavaScript fail-open behavior | Passed |
| Responsive/theme layouts (360, 390, 768, 1024, 1440, 1920 × light/dark) | 12/12; no overflow |
| Motion overflow checks at 360/390px | 8/8; no overflow |
| Axe WCAG 2.0/2.1 A/AA scans | 12/12; zero violations |
| Browser page errors / non-CSP console errors | 0 / 0 |
| JavaScript syntax and scoped Git diff checks | Passed |

These are local automated checks, not a substitute for the owner's visual review or manual keyboard/assistive-technology review.

## Reproducible evidence

- Runner: `D:\dev-toolkit\scripts\healthbridge-m3-home-motion-audit.mjs`
- Structured result: `C:\xampp85\migration-backups\healthbridge\milestone3-home-motion-20261120\home-motion-results.json`
- Human-readable report: `C:\xampp85\migration-backups\healthbridge\milestone3-home-motion-20261120\HOME-MOTION-RESULTS.md`
- Pre-edit source snapshots: `C:\xampp85\migration-backups\healthbridge\milestone3-home-motion-20261120\source-before\`

## Next gate

Pause for owner review at `http://localhost/optimized/`. Test the first-load sequence, scroll reveals, light/dark themes, mobile layout, search focus, hover and press feedback. Do not apply this motion language to another page until the owner approves the homepage and explicitly says **next**.

No staging, commit, push or deployment occurred.


## 20 November 2026 — Motion visibility timing correction

The owner reported that none of the animations were apparent. A headed Chrome reproduction at **1920×1080** exposed a timing problem that the earlier 1440×900 audit did not model:

- The old reveal controller immediately animated elements as soon as they intersected the viewport and used a positive `80px` root margin.
- At 1920×1080, the pathway cards (top ≈818px) and the capability introduction/rows (top ≈1024px) were already in or near the initial viewport. Their reveal could complete while the visitor was still reading the hero; by the time they scrolled to those sections, the content was static.
- If a refresh restored the browser to a mid-page position, the hero intro is intentionally skipped so it does not animate offscreen. The visible restored content is instead released by the recovery check.

### Correction applied

- Section reveals now use a **96px bottom inset** and individual element observation. They begin as elements enter the reading area rather than pre-playing one screen early.
- Scroll events use the same reveal-zone boundary. A restored mid-page `pageshow`/load still force-releases all already-visible elements immediately, preserving the no-invisible-content guarantee.
- The hero entrance now has a clearer 820ms sequence and larger 26px movement; section entrances use 760ms and 28px movement.
- Reduced-motion and no-JavaScript behavior remain unchanged: motion is skipped and content stays visible.
- No shared controller or global stylesheet was changed.

### Chrome reproduction and final QA

A headed Chrome 157 test at 1920×1080 confirmed:
- Hero headline uses `homeHeroRevealIn` (820ms).
- The pathway card is allowed to animate with the initial composition.
- The capability introduction, only partially inside the bottom 96px edge of the viewport, stays pending instead of pre-playing.
- The department heading remains pending offscreen and then enters with `homePageRevealIn` (760ms) when scrolled into the reading area.
- After completion it returns to opacity 1 and clears the pending/enter classes.

The final complete Playwright motion audit also passed: 8/8 section keyframe checks, hero completion/cleanup, search focus, nav underline, hover/press feedback, native mid-page refresh, reduced-motion and no-JS fallback; 12 responsive/theme checks with zero overflow; 8/8 mobile reveal overflow checks; 12 axe WCAG A/AA scans with zero violations; zero page exceptions/non-CSP console errors. JavaScript syntax and scoped Git diff checks passed.

The updated repeatable test is `D:\dev-toolkit\scripts\healthbridge-m3-home-motion-natural-entry-check.mjs`. Result: `C:\xampp85\migration-backups\healthbridge\milestone3-home-motion-visibility-timing-fix-20261120\natural-entry-results.json`.

Current cache versions: `home.css?v=12`, `home.js?v=10`. Exact pre-correction snapshots are in `C:\xampp85\migration-backups\healthbridge\milestone3-home-motion-visibility-timing-fix-20261120\source-before\`.

This correction supersedes the earlier timing description in this report. Owner visual review is still required.


## 20 November 2026 — Design and motion review using browser QA tools

The homepage was re-reviewed using the installed Playwright, axe-core and Lighthouse toolchain after the owner enabled Windows animations. Chrome 157 headed on Windows reported `prefers-reduced-motion: false`, `no-preference: true`, hero entrance active, and the homepage headline entrance running. This confirms the system preference no longer suppresses normal motion in that browser profile.

### Design/performance finding and correction

Lighthouse 13.6 identified the homepage H1 (`#home-title`) as the mobile Largest Contentful Paint element. The previous H1 animation faded from opacity 0, delaying its visibility. A dedicated transform-only `homeHeroTitleSettle` keyframe now preserves opacity 1 while retaining a restrained 16px lift. The rest of the hero choreography is unchanged. Cache version bumped to `home.css?v=13`.

### Before / after Lighthouse lab runs

| Profile / metric | Before | After run 1 | After run 2 |
|---|---:|---:|---:|
| Mobile Performance | 87 | 91 | 93 |
| Mobile Accessibility | 100 | 100 | 100 |
| Mobile Best Practices | 100 | 100 | 100 |
| Mobile FCP | 2.9s | 2.8s | 2.6s |
| Mobile LCP | 3.3s | 2.8s | 2.6s |
| Mobile Speed Index | 2.9s | 2.8s | 2.6s |
| Mobile TBT | 0ms | 0ms | 0ms |
| Mobile CLS | 0 | 0 | 0 |
| Desktop Performance | 98 | 99 | — |
| Desktop Accessibility | 100 | 100 | — |
| Desktop Best Practices | 100 | 100 | — |
| Desktop LCP | 0.9s | 0.8s | — |

Lighthouse is a throttled synthetic lab test. The before/after improvement is encouraging and repeated in two mobile runs, but it is not field Core Web Vitals or a guarantee for every device/network. The mobile LCP render-delay subpart fell from about 2.23s to about 1.02s and 0.95s in the two post-change samples. The deliberate local-demo `noindex,nofollow,noarchive` policy explains the SEO score of 63; indexing was not enabled.

### Regression and accessibility evidence

- Full Playwright homepage motion suite: PASS.
- 8/8 section motion checks, including hero completion/cleanup and interaction microstates.
- Native mid-page reload restoration: PASS after correcting the test harness to wait for instant-scroll settlement (no app-code change from that fixture correction).
- Reduced-motion and no-JavaScript fail-open: PASS.
- 12 responsive/theme combinations: zero horizontal overflow.
- 8 mobile active-motion overflow checks: zero overflow.
- 12 axe WCAG 2.0/2.1 A/AA scans: zero automated violations.
- No page exceptions or non-CSP console errors.
- Headed Chrome 157: system motion preference allows animation; headline uses `homeHeroTitleSettle` with opacity 1.
- Owner visual approval and manual keyboard/assistive-technology review remain open. Automated scores do not certify WCAG conformance.

### Scope, source and backup

Only `assets/css/pages/home.css` and `index.html` changed in this quality pass. No shared stylesheet/controller, homepage JavaScript, backend, API, DB, auth/session/RBAC, role dashboard or business logic changed. The original CSS and HTML copies are preserved at:

`C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-design-performance-pass-20261120\\source-before\\`

Evidence:
- Desktop before: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-motion-20261120\\lighthouse-desktop.json`
- Mobile before: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-motion-20261120\\lighthouse-mobile.json`
- Desktop after: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-design-performance-pass-20261120\\lighthouse-desktop-after.json`
- Mobile after runs: `lighthouse-mobile-after.json` and `lighthouse-mobile-after-repeat.json` in the same design-performance evidence folder.
- Playwright/Axe result: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-motion-20261120\\home-motion-results.json`
- Runner: `D:\\dev-toolkit\\scripts\\healthbridge-m3-home-motion-audit.mjs`

No Figma-to-implementation comparison was run because no HealthBridge Figma file/node reference was supplied in this review. Figma's design and motion context can be applied when that reference exists. Keep the page gate closed until the owner reviews this actual local homepage; do not begin motion on another page before explicit approval and `next`.
