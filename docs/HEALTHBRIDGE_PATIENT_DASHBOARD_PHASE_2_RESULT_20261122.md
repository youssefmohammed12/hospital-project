# HealthBridge Patient Workspace - Phase 2 Result and Corrections
**Date:** 22 November 2026  
**Route:** Patient Appointments  
**Implementation status:** Complete locally  
**Owner acceptance:** Awaiting owner re-check and approval

## Delivered page

The Patient Appointments route uses the Public V2 shell and its route-owned assets:

- `pages/patient/appointments.html`
- `assets/public-v2/js/pages/patient-appointments.js?v=5`
- `assets/public-v2/css/pages/patient-appointments.css?v=5`

The page provides paginated appointment cards, status filters, search, a next-up appointment, details, booking and reschedule wizards, available-slot selection, doctor-suggested time responses, appointment/reschedule cancellation, completed-visit ratings, rating history and ICS calendar export. Search stays functional behind a compact Search control beside the Filter label; the input is collapsed by default and expands on demand, retaining the existing 300ms debounce and GET query contract.

The booking and reschedule flows continue to use the existing server endpoints and payloads. The current appointment remains in place while a reschedule request is awaiting the doctor's response. Cancellation requires confirmation.

## Correction Job 1 - mutually exclusive list states

The initial list-state defect was caused by route CSS display rules overriding the native `hidden` attribute. Added a route-scoped `[hidden] { display: none !important; }` rule. The existing controller state machine was preserved.

The final lifecycle audit verifies a delayed request (loading visible, error/empty hidden), a successful 9-card response (loading/error/empty all hidden), a successful empty response (only empty visible), and a failed response (only error visible with Retry). After successful data renders, all non-list states have `hidden=true` and computed `display:none`.

Details and test evidence:
`docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_JOB_1_STATE_VISIBILITY_20261122.md`

## Correction Job 2 - wizard viewport fit and requested checks

The appointments dialog root is now portalled directly under document.body. The initial doctor-directory loading shell, booking and reschedule wizard use the dedicated viewport-contained flex layout. The audit delays the doctors GET by 450ms and verifies that the loading spinner panel and title are already in view before the department tiles are available. Once actions are present, the wizard footer is pinned as a stable sibling below the scrollable step body; the title receives focus without scrolling the underlying page. At desktop 1440 x 900 and at 320px widths (740px and 640px heights), the loading, Department and step-three states all fit the viewport without an inner wizard-body scrollbar.

| Viewport | Initial loading shell | Department step | Step 3 | Inner wizard scrollbar |
|---|---:|---:|---:|---|
| 1440 x 900 | 289.10 px | 446.45 px | 561.38 px | None |
| 320 x 740 | 295.65 px | 494.86 px | 574.96 px | None |
| 320 x 640 | 295.65 px | 494.86 px | 576.55 px | None |

The test also confirms the department is not preselected, the loading shell opens in view before doctor data arrives, Continue remains visible on the Department step, and Back/Continue remain visible on step three.

Other checks and boundaries:
- **Taken slot:** a synthetic fixture represents doctor 701 on 29 November 2026 with existing confirmed appointment 57999 occupying 09:30. The V2 renderer shows "9:30 AM" and "Taken" and disables the button. The current ScheduleService filters booked ranges out of its available-slots response; the live endpoint does not currently send taken-slot records. The front end supports an explicit unavailable slot entry, and the synthetic response tests that rendering without changing the existing PHP/API contract.
- **Calendar export:** labelled "Add to calendar" buttons appear on future upcoming/confirmed/reschedule-requested cards and in their Details dialog. Completed, cancelled and missed history has no calendar action. The existing demo patient has no future visit, so the action is absent from that real account's history. A synthetic future visit verifies the card/details controls and ICS export, including V1 `HH:MM:SS` time and V2 `duration_minutes`.
- **Cancellation:** the confirmation now names the selected doctor, appointment date and time before submission.
- **Encoding:** the wizard/controller contained stored mojibake sequences; this was not only a text-extraction artefact. The HTML, JS and CSS were repaired. Final strict UTF-8 checks pass with BOMs; the HTML declares `<meta charset="UTF-8">`. Byte sizes: HTML 7,142; JS 68,339; CSS 37,310. No known mojibake sequences remain in the three source files.
- The Appointments route uses `patient-appointments.js?v=5` and `patient-appointments.css?v=5`. All nine patient routes now use `patient-shell.js?v=3`, `patient-dashboard.css?v=7` and the shared `components.css?v=10` brand component.

Details and test evidence:
`docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_JOB_2_WIZARD_AND_SLOT_DETAILS_20261122.md`

## Wizard spacing and focus refinement - 1920 x 858

The owner follow-up audited all four steps at **1920 x 858** and replayed the backed-up pre-follow-up route assets with the same nine-department/five-doctor fixture. The old last-row-to-button gap measured 14.85px for both Steps 1 and 2; the larger screenshot-reported blank band was not reproduced by this local source/fixture. The new fixed footer layout (no automatic top margin; footer outside the scroll body) measures 15.97px from the last Step 1 tile row to Continue and 15.92px from Dr. Sherif Amin's Step 2 row to Back/Continue. Header gaps measure 15.97px and approximately 16px; footer padding is 14px above/below. Step 3 empty-state clearance is 17.92px and slot-grid gaps are 12px; Step 3 focuses the date field. Step 4 label/value spacing is 6px and Notes/footer clearance is 17px. All tested states fit at 1920 x 858 without an inner wizard-body scrollbar.

| Wizard state | Panel height | Body client / scroll height | Inner scrollbar |
|---|---:|---:|---|
| Step 1 - 9 departments / 5 rows | 812.61px | 573 / 573px | None |
| Step 2 - 5 doctors | 718.27px | 477 / 477px | None |
| Step 3 - empty availability state | 575.30px | 334 / 334px | None |
| Step 3 - four slot rows | 704.11px | 463 / 463px | None |
| Step 4 - Review and Notes | 681.73px | 441 / 441px | None |

Full spacing and before/after baseline report: `docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_WIZARD_SPACING_20261122.md`.

## Persistent wizard transitions and shared patient brand

The booking/reschedule dialog is now one persistent shell. The dialog panel, header, progress wrapper and footer keep the same DOM node identity over Step 1→2 and Step 2→3. Only the step view is replaced: a 230ms opacity/transform crossfade with a slight vertical shift, while the panel height animates over 230ms. The progress line fills across completed steps and circles turn to ✓. Reduced-motion mode performs an instant content swap with zero panel/content transition and disables the shared decorative shimmer. Step actions are gated during the short transition.

Patient shell and booking wizard now reuse one `HealthBridgeBrand.markup()` helper and the shared Login `.logo`, `.home-brand-mark`, `.auth-icon` and `.hb-wordmark` styles. The building SVG path matches Login exactly, with the shared HealthBridge idle shimmer and hover letter-wave. Tests verify the logo path, 12 wordmark letters, shimmer and hover-wave animation, shell brand across five viewports and both themes, and wizard brand equality. All nine patient route pages load the updated shared shell and brand component.

Detailed report: `docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_OWNER_FOLLOW_UP_20261122.md`.

## Final automated verification

**PASS - 319/319 assertions across 26 browser states.**

| Measure | Result |
|---|---:|
| Assertions | 319 / 319 |
| Browser states | 26 |
| axe WCAG 2.0/2.1 A/AA scans | 4 |
| axe violations | 0 |
| Mocked API requests | 77 |
| Intercepted POST requests | 9 (7 mutation flows, 2 read-only slot lookups) |
| Unexpected API requests | 0 |
| External / failed requests | 0 / 0 |
| Page errors / unexpected console errors | 0 / 0 |
| Expected handled diagnostic logs | 1 (synthetic failed-request state) |
| Screenshots | None |

Coverage includes all five responsive widths (1440, 1024, 768, 390 and 320px) in dark/light themes; delayed loading, successful empty and failure states; search collapsed/open/focus and the unchanged debounced GET query; booking and reschedule payloads; cancellation and suggestion actions; rating; wizard first-open and 1920 x 858 geometry; backed-up/current Step 1/2 gap comparison; persistent dialog/header/progress/footer identity through transitions; 230ms content/panel transitions; progress fill/checkmarks; reduced-motion instant swap; exact shared Login SVG and wordmark animations; the full four-step spacing audit (nine departments, four slot rows and Step 4 Notes); date-field focus; taken-slot rendering; future-only calendar actions and ICS data; UTF-8 source verification; focus and deep-link behavior.

All API data and POST requests in the browser run were intercepted with synthetic fixtures. No real patient appointment, rating or other database record was changed.

Runner:
`D:\dev-toolkit\scripts\healthbridge-patient-appointments-phase2-audit-20261122.mjs`

Evidence:
`C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase2-appointments-20261122\qa\patient-appointments-phase2-browser-audit.json`

## Scope and gate

No PHP endpoint, service, API payload contract, database schema/data, authentication/session, CSRF, RBAC or booking rule was changed. Existing legacy appointment scripts remain on disk but are not loaded by this Public V2 route. No screenshots, staging, commit, push or deployment were performed.

All requested correction rounds have passed local automated verification. Phase 2 is **not being marked owner-approved**; it is ready for your re-check. Phase 3 remains paused until Phase 2 is accepted.

Legacy references on this page: none.
