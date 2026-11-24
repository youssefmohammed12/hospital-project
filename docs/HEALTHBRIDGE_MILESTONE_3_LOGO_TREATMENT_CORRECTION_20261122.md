# HealthBridge — One Logo Treatment Everywhere (Correction)

**Date:** 22 November 2026  
**Owner decision:** The Login navbar icon treatment is the canonical brand treatment.  
**Status:** Implemented and locally verified.

## Approved visual treatment

Every in-page brand mark now uses:
- SVG stroke: #e8d1a8 (gold).
- Square outline: 1px solid rgba(217, 191, 150, 0.48).
- Square background: transparent.
- Existing SVG path data, dimensions, border radius, spacing and layout are preserved.

The auth story-panel container and wordmark block remain borderless. Only the icon's own rounded square has the approved gold outline.

## Coverage by page and location

| Page | Navbar/header | Footer | Additional brand locations |
|---|---|---|---|
| Home | Gold outlined, transparent home-brand-mark | Gold outlined, transparent shared footer mark | — |
| About | Gold outlined, transparent home-brand-mark | Gold outlined, transparent shared footer mark | About project/product preview mark uses the same treatment |
| Doctors | Gold outlined, transparent home-brand-mark | Gold outlined, transparent shared footer mark | — |
| Services | Gold outlined, transparent home-brand-mark | Gold outlined, transparent shared footer mark | — |
| Contact | Gold outlined, transparent home-brand-mark | Gold outlined, transparent shared footer mark | — |
| Login | Gold outlined, transparent navbar logo-icon | Gold outlined, transparent shared footer mark | Auth story-panel brand icon restored to the same treatment |
| Register | Gold outlined, transparent navbar logo-icon | Gold outlined, transparent shared footer mark | Auth story-panel brand icon and patient preview-card issuer mark use the same treatment |

The prior teal-filled / white-stroke footer treatment has been removed. The public header's filled brand background has also been removed. The Register preview-card logo was aligned to the same gold/transparent treatment so no alternative filled logo treatment remains in the migrated page set.

## Implementation

Only the existing colour/fill/border styling was changed:
- assets/public-v2/css/components.css: Public V2 header mark border, fill and stroke.
- assets/public-v2/css/footer.css: Shared footer mark border, fill and stroke.
- assets/public-v2/css/pages/auth.css: Auth navbar icon, auth story-panel icon, and Register preview-card issuer mark.

No SVG path data, HTML structure, sizes, radii, flex/grid dimensions, gaps, margins, padding, positioning, typography, backend, API, auth, database or session behavior was changed.

CSS cache references were advanced so the updated styles load:
- All seven migrated pages: components.css?v=5 and footer.css?v=3.
- Login and Register: auth.css?v=6.
- About page CSS reference: about.css?v=8 (cache-key refresh; its stylesheet contents did not need editing).

## Verification

**Browser audit: PASS — 291/291 assertions.**
- Seven pages × both dark and light themes = 14 browser states.
- Each state checked the page navbar/header and loaded shared footer.
- Every mark's path remained identical.
- Every checked mark computed to gold stroke rgb(232, 209, 168), a 1px gold outline rgba(217, 191, 150, 0.48), and transparent background rgba(0, 0, 0, 0).
- Login and Register auth story-panel marks passed while their surrounding brand containers remained borderless.
- About's extra project preview mark and Register's patient preview-card mark passed.
- Existing icon slot geometry/radii were preserved; no mobile or desktop horizontal overflow.
- Zero external requests, failed requests or POSTs. No screenshots were created.

**Static source audit: PASS — 27/27 checks.**
- Confirmed the public header and footer no longer use their previous filled/white icon treatments.
- Confirmed auth navbar, auth brand block and Register patient-card brand rules use the approved transparent/gold treatment.
- Confirmed all seven HTML routes still contain the unchanged approved SVG path and load the new CSS cache versions.

**Raw browser evidence:**
C:\xampp85\migration-backups\healthbridge\logo-treatment-correction-20261122\qa\logo-treatment-audit.json

**Source rollback copies and SHA-256 manifests:**
C:\xampp85\migration-backups\healthbridge\logo-treatment-correction-20261122\source-before\
C:\xampp85\migration-backups\healthbridge\logo-treatment-correction-20261122\source-before-manifest.json
C:\xampp85\migration-backups\healthbridge\logo-treatment-correction-20261122\final-source-manifest.json

**Runners:**
D:\dev-toolkit\scripts\healthbridge-logo-treatment-correction-20261122.cjs
D:\dev-toolkit\scripts\healthbridge-logo-treatment-audit-20261122.mjs
D:\dev-toolkit\scripts\healthbridge-logo-treatment-static-audit-20261122.cjs

No screenshots, staging, commits, pushes or deployment were performed.
