# HealthBridge Services Hero Diagram - Owner-Directed Fix
Date: 21 November 2026

## Scope
This follow-up implements the owner's decisions after inspecting the rendered Services hero. Application changes are limited to `services.html` and `assets/public-v2/css/pages/services.css`. The stylesheet cache key is now `services.css?v=6`.

## Final diagram behavior
- Desktop (above 1240px): removed all three connector arrowheads. Each horizontal connector remains a clean line into its existing ring dot on the Shared system hub spine. Node, caption and hub placement are preserved.
- Phone/tablet (1240px and below): removed the three individual row arrows. One CSS Grid-spanning vertical spine is centred by the map container; it begins at the lower edge of the first role card and ends exactly at the Shared system hub edge. Three small ring dots sit at the role-card edges and one arrowhead points into the hub. Flow captions remain below their cards and to the left of the spine.
- Signal: desktop uses three filled 9px gold dots with a soft glow, one on each connector, staggered by 1.2 seconds in a 3.6-second cycle. Each has a slightly stronger arrival scale pulse. Phone/tablet uses one filled 9px gold dot travelling down the continuous spine, with an arrival pulse.
- All movement uses CSS transform and opacity only. No JavaScript, timers, dependencies, images or external requests were added. `prefers-reduced-motion: reduce` disables all travelling/pulse animations while retaining the static connectors, ring dots and final arrowhead.

## Validation
- Dedicated owner-fix Playwright audit: 332/332 assertions passed across 22 responsive/theme states (1440, 1280, 1241, 1240, 1024, 900, 768, 640, 560, 390 and 320px; light and dark), plus four reduced-motion states (1440/390px; light/dark).
- Desktop connector-to-port alignment remains within 0px horizontally and 0.5px vertically. Phone spine is centred by the grid and meets both the first role-card edge and the hub edge exactly. Desktop arrowheads and all separate phone row arrows are absent.
- No browser errors, failed requests, local HTTP errors, blocked API writes or unexpected external requests during the dedicated audit.
- Full Services audit: 99 layout checks, 11 interactions and 13 role-routing checks passed. Four axe WCAG A/AA scans recorded zero violations and 23 passes each; one existing layered-hero-gradient contrast item remains incomplete for manual review per scan.
- Visible section spacing: 12/12 states passed. Hero to capabilities: 70-74px; capabilities to workflow: 71-86.52px; workflow to FAQ: 71-75px; FAQ to CTA: 68-74px; CTA to footer: 74-86px.
- Post-change performance sample (1440/390px; light/dark): 16.67ms mean frame, 16.7-16.8ms p95, 180 sampled frames per 3-second state, zero frames above 24ms or 40ms, zero layout duration and zero layout shifts. Script duration: 5.64-9.59ms; style recalculation: 23.48-29.36ms; task duration: 97.89-129.57ms. Transfer: 86.57KB, up about 1.89KB from the preceding animated version. Desktop has six active CSS animations (three travel tracks + three arrival pulses); phone has two (spine travel + arrival pulse).
- Two desktop headless samples included 301ms and 307ms startup long tasks at 62.2ms and 52.8ms after navigation; phone samples had none. These occurred during initial startup, outside the measured 3-second animation frame sample. This is lab data, not a real-device Lighthouse result.

## Acceptance screenshots and evidence
Rendered screenshots of the final diagram are saved at:
- `C:\xampp85\migration-backups\healthbridge\services-hero-owner-fix-20261121\qa\screenshots\1440-dark.png`
- `C:\xampp85\migration-backups\healthbridge\services-hero-owner-fix-20261121\qa\screenshots\390-dark.png`
- `C:\xampp85\migration-backups\healthbridge\services-hero-owner-fix-20261121\qa\screenshots\1440-light.png`
- `C:\xampp85\migration-backups\healthbridge\services-hero-owner-fix-20261121\qa\screenshots\390-light.png`

Audit report and summary are `qa/owner-fix-audit.json` and `qa/owner-fix-summary.json`; performance is `qa/performance/performance-owner-fix-final.json`. The focused runner is `D:\dev-toolkit\scripts\healthbridge-services-hero-owner-fix-audit-20261121.mjs`; performance runner is `D:\dev-toolkit\scripts\healthbridge-services-hero-owner-fix-performance-20261121.mjs`. Pre-edit backups and SHA-256 verification are in `source-before/manifest.json` under the same backup root.

## Boundaries
Only the Services hero HTML and page-owned CSS changed in the application. No controller/JavaScript, shared foundation, other route, API, database, authentication/session/CSRF/RBAC, booking rule, dashboard or hosted resource changed. No dependency was added. No files were staged or committed, and nothing was pushed or deployed. This is focused Services QA, not the final full HealthBridge regression; that remains due at the end of Milestone 3.


## Follow-up: four-second timing and clean desktop signal line

Owner requested a slower signal and a desktop connector line that does not run through its caption. Updated desktop layout so the connector remains a single uninterrupted line through the row centre, while each flow caption sits directly beneath it. The row/connector centre remains aligned with the role-card centre and matching hub ring. The label has a tighter, quieter typographic treatment to keep it clearly separate from the signal path. Phone/tablet captions retain their existing under-card placement beside the centred spine.

All desktop and phone/tablet signal and arrival animations now use a 4-second cycle. Desktop start offsets are 0s, 1.333s and 2.666s for evenly staggered signals. Reduced-motion behavior is unchanged. CSS cache reference is now `services.css?v=7`.

Only the Services stylesheet and its HTML cache reference changed in this follow-up. Per the owner's explicit instruction, no tests or screenshot reruns were performed for this small adjustment. The previous 332/332 diagram audit, full Services regression and performance measurements apply to the preceding implementation, not as fresh validation of this timing/label-placement follow-up. No other page or application behavior was changed. Pre-edit copies and hashes are in `four-second-desktop-line/source-before/`.


## Follow-up: slightly larger desktop flow captions

Owner requested a small increase to the text placed below the desktop connector lines. The desktop `.services-system-map__flow-caption` font size is now `0.55rem` (previously `0.5rem`). Phone/tablet-specific caption sizes and the 4-second signal cycle/layout are unchanged. Cache reference remains `services.css?v=7`.

Only `assets/public-v2/css/pages/services.css` changed in this micro-adjustment. No tests were run, per the owner's request. Pre-edit CSS and matching SHA-256 are in `four-second-desktop-line/desktop-caption-font/source-before/manifest.json`.
