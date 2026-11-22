# HealthBridge About Hero — Build Journey Replacement
Date: 21 November 2026

## Owner brief
Replaced the About hero's three numbered workspace rows with a miniature project-build timeline. The edit is limited to the About route and its page-owned stylesheet. The existing light card surface, width/placement, brand/status header, main heading, synthetic-record note and closing learning-project caption remain in place.

## Final wording and dates
**Overline:** HOW IT WAS BUILT

1. **THE FOUNDATION — Three connected workspaces**  
   Booking, medical records and prescriptions in one application.  
   No milestone date is assigned to this foundational point.

2. **MILESTONE 2 — Security and data design**  
   Local security and reliability hardening, alongside the V2 database redesign.  
   **Completed 20 November 2026.** The wording intentionally describes the V2 database redesign without claiming the proposed V2 schema had already been deployed. Milestone 2 is recorded as closed for local scope; hosted/external release gates remain separate.

3. **MILESTONE 3 — Public V2 redesign**  
   A page-by-page rebuild of the public experience.  
   **In progress since 20 November 2026.** This matches the project completion plan and the approved incremental presentation-layer strategy.

## Visual and motion implementation
- Vertical 1px brass/gold spine with three outlined ring markers; the line is centred on the milestone markers.
- Filled 9px gold signal travels down the spine over a 4-second cycle. It holds briefly at each milestone marker.
- A soft ring pulse is timed to each signal arrival. The movement uses CSS transform and opacity only; no JavaScript loop, timer, dependency or external asset was added.
- `prefers-reduced-motion: reduce` disables both the travelling signal and arrival pulses, while keeping the static spine/rings visible.
- Both themes use the existing About tokens and contrast surfaces. Mobile copy is allowed to wrap; the third milestone's status was shortened to “IN PROGRESS · SINCE 20 NOV 2026” so the row remains tidy at 320px.
- Hero-to-stats visible gap: **65px desktop**, **66px phone/320px**. Card-to-stats visible gap: **89.38px desktop**, **66px phone/320px**. Both are within the 65–90px rhythm.

## Acceptance screenshots
Final rendered captures:
- `C:\xampp85\migration-backups\healthbridge\about-build-journey-20261121\qa\screenshots\1440-dark.png`
- `C:\xampp85\migration-backups\healthbridge\about-build-journey-20261121\qa\screenshots\390-dark.png`
- `C:\xampp85\migration-backups\healthbridge\about-build-journey-20261121\qa\screenshots\1440-light.png`
- `C:\xampp85\migration-backups\healthbridge\about-build-journey-20261121\qa\screenshots\390-light.png`

## Validation
Dedicated hero/timeline review: **66/66 assertions passed** across six responsive/theme states (1440, 390 and 320px × dark/light) and two reduced-motion states. It checked no overflow, all milestone copy fits, marker/spine alignment, retained card copy/header and stylesheet version. No browser errors, failed requests, local HTTP errors or blocked API writes.

Full About regression: **20/20 responsive/theme states**, zero horizontal-overflow cases, **8 axe WCAG 2.0/2.1 A/AA scans with zero violations**, 35 anchors / seven unique internal targets / zero failures, zero page/console errors, failed requests, local HTTP errors or blocked writes. Shared footer markup/style parity, mobile navigation, theme toggle, reduced-motion and reveal fallback all passed. Detailed full-page result: `qa/full-audit/about-public-v2-results.json`.

## Files and boundaries
Application files changed:
- `about.html` — About page stylesheet cache reference advanced to `about.css?v=6`; old three-row list replaced with the timeline markup.
- `assets/public-v2/css/pages/about.css` — timeline geometry, light/dark treatment, 4-second CSS signal/pulses, reduced-motion fallback and hero-spacing rhythm.

No other route, shared foundation, page controller/JavaScript, PHP/API, database, authentication/session/CSRF/RBAC or business behavior changed. No dependency was added. No staging, commit, push or deployment occurred. Owner visual review is pending.

Pre-edit source backups and SHA-256 manifest are in `C:\xampp85\migration-backups\healthbridge\about-build-journey-20261121\source-before\`. Final source/evidence hashes: `final-source-manifest.json`.
