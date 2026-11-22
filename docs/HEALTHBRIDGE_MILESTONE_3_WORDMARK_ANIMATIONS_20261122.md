# HealthBridge — Brand Wordmark Animations

**Date:** 22 November 2026  
**Owner request:** A one-shot hover letter wave plus an occasional idle shimmer and underline.  
**Status:** Implemented, owner-observed issue corrected, and locally verified.

## Coverage

The shared wordmark motion is applied to the navbar/header and shared footer wordmarks on Home, About, Doctors, Services, Contact, Login and Register. Login and Register story-panel wordmarks are included. All seven routes were checked in dark and light themes.

## Owner feedback and correction

The first pass technically animated but had two visible problems: the underline was too brief/faint, and the title could lose its readable fill during hover because the original glyph fill had been made transparent while a moving gradient tried to redraw it.

The hover treatment now leaves each original character fully opaque in its theme color and places a separate gold text overlay above it. The underline also has a longer, more visible draw/hold/fade phase. The idle shimmer and underline pause during hover or keyboard focus so they do not compete with the interaction wave.

## Hover and keyboard-focus wave

- Twelve letters animate left-to-right.
- Each letter runs for 175ms with ease-out and a single iteration.
- Stagger: 25ms per letter. The last letter begins at 275ms, so the complete wave finishes at 450ms.
- Peak lift: 2px.
- A soft gold overlay briefly tints the glyph while it lifts; the base letter retains its original computed text colour/fill throughout.
- Pointer hover is enabled only when the device reports (hover: hover). Keyboard focus-visible on the navbar/footer brand link triggers the same treatment.

## Idle shimmer and underline

Two idle CSS animations run in a 10-second cycle:

- The low-opacity gold shimmer sweeps once across the text.
- A 1px gold underline draws from left to right, holds clearly, then fades.
- The active window is 77–91% of the cycle (about 1.4 seconds); the remaining 8.6 seconds are visually still.
- The underline's active window consists of roughly 0.3s draw, 0.8s hold and 0.3s fade. Its peak opacity is 0.92.
- The shimmer and underline pause while the user hovers/focuses the title.

Both effects are fully disabled under prefers-reduced-motion: reduce. The title does not use transparent base text, animated blur/filter/shadows, or animated layout properties.

## Performance approach and local measurements

The idle effect uses only **two running animations per wordmark**: one aria-hidden shimmer text layer and one underline pseudo-element. Twelve glyph-wave and twelve gold-overlay animations are created only during hover or keyboard focus. No new JavaScript, timer, event listener, observer, package, font download or external resource was added.

A local Chromium/XAMPP performance sanity run completed 14 samples (seven routes × dark/light):
- Median full-page transferred data: **160.2 KB**.
- Median load event: **293ms**.
- Median first contentful paint: **328ms**.
- Shared components.css transfer: **17.8 KB**.
- Zero external requests, failed requests or POSTs.

These are unthrottled local lab observations, not field Core Web Vitals. The Home route includes its existing local hero assets, which is why its page transfer is larger than the other routes.

## Verification

**Focused Playwright browser audit: PASS — 499/499 assertions.**

Coverage comprises seven routes × dark/light plus one reduced-motion state per route (21 states total). It verifies:
- All wordmarks retain the exact HealthBridge text and 12 character targets.
- All two idle animations per wordmark use the 10-second cycle and become visible only in the designated window; the long still interval remains visually hidden.
- The hover wave and gold tint each run once, at 175ms per letter with 25ms staggering and 450ms total duration.
- Every base letter keeps its theme text color/fill while the gold overlay is applied.
- Keyboard focus-visible triggers the same left-to-right wave and tint.
- Hover does not resize the wordmark box.
- Reduced motion disables idle shimmer, underline, hover lift and gold overlay.
- Desktop and 390px mobile layouts have no horizontal overflow.
- Zero page exceptions, unexpected console errors, external requests, failed requests or POSTs.

**Performance runner:**  
D:\dev-toolkit\scripts\healthbridge-wordmark-performance-check-20261122.mjs

**Performance evidence:**  
C:\xampp85\migration-backups\healthbridge\wordmark-animations-20261122\qa\wordmark-performance.json

**Animation runner:**  
D:\dev-toolkit\scripts\healthbridge-wordmark-animation-audit-20261122.mjs

**Animation evidence:**  
C:\xampp85\migration-backups\healthbridge\wordmark-animations-20261122\qa\wordmark-animation-audit.json

**Correction rollback snapshot:**  
C:\xampp85\migration-backups\healthbridge\wordmark-animation-observed-issues-20261122\source-before\  
SHA-256 manifest: C:\xampp85\migration-backups\healthbridge\wordmark-animation-observed-issues-20261122\source-before-sha256.txt

## Source and release scope

The shared motion is in assets/public-v2/css/components.css; all seven migrated pages load components.css?v=10. The existing shared footer loader now uses footer.js?v=3, requesting the canonical fragment at includes/fragments/footer.html?v=3.

No PHP, API, database, authentication, session, CSRF or RBAC behavior changed. No screenshots were captured or saved. No files were staged, committed, pushed or deployed.

Legacy references on this page: none.
