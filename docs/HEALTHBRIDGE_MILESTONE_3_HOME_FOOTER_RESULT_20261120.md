# HealthBridge — Homepage footer polish result

**Date:** 20 November 2026  
**Scope:** Homepage footer only  
**Status:** Implemented and owner-approved on 20 November 2026 for reuse on the Doctors page only; other pages remain gated by their own review.

## Changes applied

1. **Unified footer finish:** Light mode now uses the same deep-green `#102B2B` footer as dark mode, with ivory body copy and links, gold section headings, and the existing teal brand square with its ivory plus. The closing CTA remains pale mint immediately above the footer; a subtle ivory-green top rule gives the mint-to-green transition a deliberate edge. Dark-mode footer background and base treatment remain deep green.
2. **Column update:** Removed the Demonstration Scope column. The original HealthBridge brand mark and description are unchanged. The Explore and Your Access groups are unchanged. Added a Departments group with Cardiology, Dentistry, Dermatology, General Practice, Gynecology and All departments.
3. **Department routing:** Specialty links use the existing `doctors.html?q=...` contract. The All departments link points to `doctors.html` without a query.
4. **Bottom row:** Kept the copyright line and added one quiet scope line: “Portfolio demonstration · Synthetic records only · No live medical services”.

## Verification

**Playwright + axe-core result: PASS**
- Homepage HTTP 200.
- 12 theme/render-state checks across 320, 360, 390, 768, 1024 and 1440px in dark and light modes.
- Deep-green footer, ivory copy/links/wordmark, gold headings, teal mark and ivory plus confirmed in both themes.
- Footer bottom contains exactly two paragraphs: “© 2026 HealthBridge” and the single scope line.
- Pale-mint CTA immediately precedes the deep-green footer; the seam/border is consistent in both themes.
- 12 responsive document-width checks; zero horizontal overflow.
- 12 axe WCAG 2.0/2.1 A/AA scans; zero violations.
- All six department destinations returned HTTP 200. The five specialty URLs prefilled the expected search term and selected the matching department chip; All departments selected the unfiltered chip.
- The serialized static DOM for `#main-content` matches the pre-edit HTML baseline exactly. The original footer brand block, brand description, Explore group and Your Access group are also unchanged.
- Zero browser exceptions and non-CSP console errors.

**Lighthouse 13.6 local lab checks**
- Mobile: Performance 90, Accessibility 100, Best Practices 100; FCP 2.9s, LCP 2.9s, TBT 0ms, CLS 0.
- Desktop: Performance 98, Accessibility 100, Best Practices 100; FCP 0.9s, LCP 0.9s, TBT 0ms, CLS 0.
- SEO 63 is expected for this local portfolio demo because its deliberate `noindex,nofollow,noarchive` policy remains enabled. It was not changed.
- These are throttled local lab results, not field Core Web Vitals.

## Files changed

- `C:\\xampp85\\htdocs\\optimized\\index.html`
- `C:\\xampp85\\htdocs\\optimized\\assets\\css\\pages\\home.css`

Stylesheet cache reference: `home.css?v=15`.

No page body/main content, PHP, API, database, auth/session/RBAC, dashboard, JavaScript, shared stylesheet or shared controller was changed in this footer pass. The existing dirty changes in `assets/css/style.css`, `assets/js/core/main.js` and `assets/js/pages/home.js` were not modified by this work. No staging, commit, push or deployment occurred.

## Backup and evidence

Pre-edit copies:
`C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-footer-polish-20261120\\source-before\\`

SHA-256 before:
- `index.html`: `6e9a20904dde09673c9de26c1f8014f5e240dd838c913a9df1f9b987bbcd1d96`
- `home.css`: `ccc877cfe4a3a46f85a7785000307331651008fea2aaaa765460ef4b3d52db9f`

SHA-256 after:
- `index.html`: `879be2a762e3ef7ba26916a8a30acb127bd5834b3623ba68b8d40adc4b254568`
- `home.css`: `ee452ac59eb8bd352131984e80434deab65629aefb0b428cac078982c6413096`

Repeatable audit: `D:\\dev-toolkit\\scripts\\healthbridge-m3-home-footer-audit.mjs`  
Playwright/Axe JSON: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-footer-polish-20261120\\home-footer-results.json`  
Lighthouse mobile: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-footer-polish-20261120\\lighthouse-mobile.json`  
Lighthouse desktop: `C:\\xampp85\\migration-backups\\healthbridge\\milestone3-home-footer-polish-20261120\\lighthouse-desktop.json`

## Approval gate

The owner explicitly approved this footer pattern on 20 November 2026. It has been reused on the Doctors page only as part of the separate Doctors premium redesign. About, Services, Contact and role workspaces remain outside this approval and must still pass their own page-by-page review before rollout.
