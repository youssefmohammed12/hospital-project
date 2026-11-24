# HealthBridge — Patient Appointments: Owner Follow-Up
**Date:** 22 November 2026  
**Route:** Patient Appointments / Public V2  
**Status:** Implemented and verified locally  
**Viewport for visible-gap measurements:** 1920 × 858

## 1. Step 1 and Step 2 content-to-footer spacing

I replayed the backed-up pre-follow-up controller and stylesheet at 1920 × 858 with the same synthetic fixture used by the new layout check: nine department tiles (including Pediatrics) and five Cardiology doctors (ending with Dr. Sherif Amin).

The baseline replay did **not** reproduce the large blank band described in the owner screenshots. Its rendered last-content-row-to-first-footer-button gaps measured **14.85px** in both steps. That is close to the 12–15px rhythm, so I cannot honestly report that the old local build showed a much larger gap under this fixture.

The corrected layout removes the footer's automatic top margin and moves the footer out of the scrollable content body. The visible gaps are now controlled by the body bottom edge and footer divider/padding, rather than expandable free space:

| State | Backed-up pre-follow-up gap | Current gap | Current visible gap |
|---|---:|---:|---|
| Step 1 — last department tile row to Continue | 14.85px | 15.97px | ~16px |
| Step 2 — Dr. Sherif Amin row to Back / Continue | 14.85px | 15.92px | ~16px |

The measurements are from the last content-row bottom to the first footer button top. The footer itself uses 14px top and bottom padding; the gap from divider to button is about 14px. No extra elastic top margin remains between the content and footer.

**Owner screenshot discrepancy:** the supplied note describes a much taller blank band, but the pre-follow-up source replay with the representative nine-department/five-doctor fixture measured 14.85px. The local reproduction therefore does not confirm that larger pre-change gap. The replacement layout does make the target ~16px gap explicit and non-elastic. If the owner's screenshot shows a materially larger gap than this source replay, that exact larger state was not reproduced by the available local fixture.

## 2. Persistent wizard shell and transitions

The wizard no longer rebuilds the entire dialog for each step. It now creates one persistent dialog shell when booking/rescheduling opens. The shell contains a stable header, stable progress wrapper, one stage host for the animated step content, and a stable footer.

On step changes:
- The outgoing step fades and moves up slightly; the incoming step fades in while moving up from a small offset.
- Content opacity/transform transition duration is **230ms**.
- The dialog panel height animates to the next step's natural height over **230ms**.
- The header, progress element, footer and dialog panel remain the same mounted DOM nodes across Step 1 → 2 and Step 2 → 3.
- The progress line fills as steps complete; completed circles change to ✓, and the current step styling advances.
- While a transition is running, step actions are temporarily disabled to avoid double navigation.

The reduced-motion test uses an emulated `prefers-reduced-motion: reduce` environment. It confirms an immediate single-view swap, zero transition duration for the step and panel, no inline animated height, and no decorative wordmark shimmer; the persistent shell and progress state are still retained.

## 3. Shared HealthBridge brand

The patient workspace shell and booking wizard now call the same `HealthBridgeBrand.markup()` helper exposed by the patient-shell controller. That helper uses the existing shared `.logo`, `.home-brand-mark`, `.auth-icon` and `.hb-wordmark` component structure and the existing `components.css` animations. It does not introduce a newly drawn icon or a second wordmark implementation.

The building SVG path is the exact Login building-mark path:
`M4 21V4h16v17M8 8h2m4 0h2M8 12h2m4 0h2M9 21v-4h6v4M12 1v5m-3-3h6`.

The 12-letter HealthBridge wordmark reuses the existing idle shimmer animation (`hbWordmarkShimmer`) and hover letter-wave (`hbWordmarkWave`). Browser checks compare the actual SVG path and wordmark structure between the patient shell and wizard and verify the hover animation name. Reduced-motion mode also disables the brand animation through the existing shared component rules.

The shared patient shell is loaded by all nine patient workspace routes. Each now references `patient-shell.js?v=3` and `patient-dashboard.css?v=7`; all nine include the existing shared `components.css?v=10` component sheet. The Appointments route assets are `patient-appointments.js?v=5` and `patient-appointments.css?v=5`.

## 4. Rendered wizard measurements at 1920 × 858

| State | Panel height | Body client / scroll height | Inner body scrollbar |
|---|---:|---:|---|
| Step 1 — nine departments / five rows | 812.61px | 573 / 573px | None |
| Step 2 — five doctors | 718.27px | 477 / 477px | None |
| Step 3 — empty availability | 575.30px | 334 / 334px | None |
| Step 3 — 16 slots / four rows | 704.11px | 463 / 463px | None |
| Step 4 — review and Notes | 681.73px | 441 / 441px | None |

Other audited spacing in the same rendering: header gaps 15.97px / 15.97px (Step 1); Step 3 empty-state-to-footer clearance 17.92px; Step 4 review label-to-value 6px and Notes-to-footer clearance 17px. All five measured states fit at the requested 1920 × 858 viewport without an inner wizard-body scrollbar.

## 5. Verification

Final Playwright audit: **PASS — 319/319 assertions across 26 browser states**. Four axe WCAG 2.0/2.1 A/AA scans report zero violations. The browser run recorded 77 mocked API requests and 9 intercepted POST requests; zero external, failed or unexpected API requests; zero page errors and unexpected console errors. One expected, handled diagnostic was recorded for the synthetic failed-request scenario. No screenshots were created.

Additional coverage includes:
- Five responsive widths (1440, 1024, 768, 390 and 320px) in both dark and light themes, including the shared patient header wordmark.
- Pre-follow-up baseline gap replay using the backed-up Appointments controller and stylesheet.
- New Step 1 and Step 2 visible gap measurements.
- Stable DOM node identity for the panel, header, progress wrapper and footer across transitions.
- 230ms content/panel transition properties, progress completion behaviour, and reduced-motion instant swap.
- Exact shared SVG/wordmark comparison, idle shimmer identity and hover letter-wave.
- Existing Appointments list-state, search, booking/reschedule payload, cancellation, rating, calendar, taken-slot, UTF-8 and responsive checks.

All browser APIs and POSTs were intercepted with synthetic fixtures. No real patient appointment or database record was created or changed.

Runner:
`D:\dev-toolkit\scripts\healthbridge-patient-appointments-phase2-audit-20261122.mjs`

Evidence:
`C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase2-appointments-20261122\qa\patient-appointments-phase2-browser-audit.json`

## 6. Scope and gate

Updated locally: Patient Appointments controller and stylesheet, shared patient-shell controller and patient-dashboard stylesheet, the nine patient-page cache references, QA runner, README and project reports. No PHP, API contract, database schema/data, auth/session, CSRF, RBAC or booking rule was changed. No real patient writes, screenshots, staging, commits, pushes or deployment.

Phase 2 remains awaiting owner acceptance. Phase 3 remains paused.

Legacy references on this page: none.
