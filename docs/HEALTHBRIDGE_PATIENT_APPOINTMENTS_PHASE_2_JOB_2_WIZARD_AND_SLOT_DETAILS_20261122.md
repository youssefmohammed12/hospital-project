# HealthBridge Patient Appointments - Phase 2 Correction, Job 2
**Date:** 22 November 2026  
**Route:** `pages/patient/appointments.html`  
**Status:** Corrected and verified locally

## Wizard viewport correction

### Finding and correction

The dialog root was nested inside the transformed patient main-content area. That made fixed-position modal placement depend on the page's transformed ancestor. The wizard also used the generic scrollable dialog layout, so its footer was part of the scrolling content. The initial loading placeholder now receives the same wizard layout class instead of temporarily using the generic dialog shell.

The controller now relocates the appointments dialog root directly under document.body. The initial doctor-directory loading shell, booking wizard and reschedule wizard use a dedicated pa-dialog--wizard flex layout with a viewport-bound panel, a non-shrinking header and a footer pinned as a stable sibling below the scrollable step body. The owner follow-up preserves that same shell, header, progress and footer node identity across step changes. The wizard title receives focus with preventScroll, so opening the modal does not scroll the underlying page to a button or department tile. The audit deliberately delays the doctors-directory GET by 450ms; it confirms the initial spinner panel is already in view, with its title visible, before any department tile has loaded. The constrained wizard body has no inner scrollbar in the measured loading, Department or step-three states.

### Measured rendering

Measurements are from the browser's rendered DOM, not CSS estimates. The doctors-directory GET was deliberately delayed by 450ms so the initial loading shell could be measured before any department tile appears.

| Viewport | State | Rendered height | Title visible | Footer | Inner wizard scrollbar |
|---|---|---:|---|---|---|
| 1440 x 900 | Initial loading shell (doctor list pending) | 289.10 px | Yes | Not shown until booking choices load | No |
| 1440 x 900 | Department step | 446.45 px | Yes | Continue visible, pinned | No |
| 1440 x 900 | Step 3, Date & Time | 561.38 px | Yes | Back/Continue visible, pinned | No |
| 320 x 740 | Initial loading shell (doctor list pending) | 295.65 px | Yes | Not shown until booking choices load | No |
| 320 x 740 | Department step | 494.86 px | Yes | Continue visible, pinned | No |
| 320 x 740 | Step 3, Date & Time | 574.96 px | Yes | Back/Continue visible, pinned | No |
| 320 x 640 | Initial loading shell (doctor list pending) | 295.65 px | Yes | Not shown until booking choices load | No |
| 320 x 640 | Department step | 494.86 px | Yes | Continue visible, pinned | No |
| 320 x 640 | Step 3, Date & Time | 576.55 px | Yes | Back/Continue visible, pinned | No |

At desktop 1440 x 900, the wizard body has no overflow in either the initial or step-three state (`scrollHeight === clientHeight`). The 320px checks also report no body overflow in the tested first-open and step-three states. Department step 1 remains unselected until the patient chooses a tile.

## Other requested checks

### Taken-slot rendering

A synthetic availability response represents doctor ID `701` on **29 November 2026**, with existing confirmed appointment ID `57999` occupying **09:30**. The response includes a 09:30 slot marked `available: false` / `status: "taken"`.

The booking wizard renders this as **"9:30 AM" with a visible "Taken" label**. The button is disabled, carries `aria-disabled="true"`, and remains `aria-pressed="false"`; it cannot be selected or submitted. The synthetic test verified those properties.

**Backend boundary:** the current `ScheduleService::getAvailableSlots()` deliberately excludes booked ranges and returns only free slots. Therefore the real endpoint does not currently send occupied slots for the UI to display. This correction adds front-end handling for an availability-aware response and proves its rendering with the requested synthetic fixture; it does not change the existing PHP/API response contract or claim that the current live endpoint provides occupied entries.

### Calendar export

The action is a text-labelled **"Add to calendar"** button. It appears in the appointment card action row and in the Appointment Details dialog, but only when the appointment category is upcoming, confirmed or reschedule-requested and the currently scheduled date/time is still in the future. Completed, cancelled and missed appointments do not show it. A pending reschedule keeps the current scheduled appointment as the calendar event until the change is accepted.

The demo account had no future appointment, so this action was not expected to appear on its live historical/upcoming list. The synthetic future appointment test verified the card and details controls and downloaded an ICS file. It also covered a V1 `HH:MM:SS` appointment time and the V2 `duration_minutes` field: the synthetic 45-minute visit exported a 45-minute calendar event.

### Encoding

This was **not only an extraction/display artefact**. The appointment controller contained stored mojibake sequences (for example, CP1252-decoded forms of the middle dot, apostrophe, en dash and checkmark). Those source literals were repaired to their intended Unicode text.

Final source-byte checks:

| File | Bytes | Strict UTF-8 decode | BOM | Charset declaration | Mojibake sequences |
|---|---:|---|---|---|---:|
| `pages/patient/appointments.html` | 7,142 | Pass | Yes | `<meta charset="UTF-8">` | 0 |
| `assets/public-v2/js/pages/patient-appointments.js` | 68,339 | Pass | Yes | External JavaScript asset | 0 |
| `assets/public-v2/css/pages/patient-appointments.css` | 37,310 | Pass | Yes | External CSS asset | 0 |

The served wizard text now contains the intended strings: "STEP 03 · DATE & TIME", "doctor’s current schedule", and the ✓ completion mark. The result count is "Showing 1–9 of 9". The Appointments route cache references are now `patient-appointments.js?v=5` and `patient-appointments.css?v=5`.

### Search control

Search is preserved but no longer occupies permanent toolbar space. A compact Search button beside the Filter label expands the original 100-character input on demand and moves keyboard focus into it. The field starts hidden in both themes and all tested viewport widths. The existing 300ms debounce and `search` query parameter to `api/patient/appointments.php` remain unchanged.

### Wizard spacing and Step 3 focus follow-up

The spacing follow-up covers all four wizard steps at the requested 1920 × 858 viewport. The backed-up pre-follow-up source was replayed with the same nine-department/five-doctor fixture: its last-row-to-button gap measured 14.85px in Steps 1 and 2 (the larger screenshot-reported blank band was not reproduced by that source/fixture). The replacement layout has explicit ~16px gaps: Step 1 15.97px and Step 2 15.92px. The footer no longer has an automatic top margin and is a stable sibling below the scrollable body. Header gaps are 15.97px / about 16px; footer padding is 14px top/bottom. Step 3 empty-state clearance is 17.92px; the four-row slot grid uses 12px gaps. Step 4 label/value spacing is 6px and Notes/footer clearance is 17px. All measured states fit 1920 × 858 with no inner wizard-body scrollbar. Entry to Step 3 focuses the date field.

Full spacing and baseline comparison: `docs/HEALTHBRIDGE_PATIENT_APPOINTMENTS_PHASE_2_WIZARD_SPACING_20261122.md`.

### Persistent wizard shell, transitions and shared brand

The booking/reschedule wizard now retains the same dialog panel, header, progress wrapper and footer DOM nodes between steps. Step content uses a 230ms opacity/transform crossfade with a slight vertical offset; panel height changes animate over 230ms. The persistent Register-style progress line fills as steps complete and circles turn into ✓ marks. During a transition, step actions are briefly disabled to prevent double navigation. With `prefers-reduced-motion: reduce`, the step swaps immediately, panel/content transitions are zero-duration, and the existing shared wordmark motion is suppressed.

Patient-shell and wizard branding now use the same `HealthBridgeBrand.markup()` helper and the existing shared `.logo`, `.home-brand-mark`, `.auth-icon` and `.hb-wordmark` component CSS. The SVG building mark uses the exact Login path; the wordmark has 12 letter spans, the shared idle shimmer and hover letter-wave. All nine patient routes reference patient-shell.js?v=3 and patient-dashboard.css?v=7, alongside components.css?v=10. The Appointments route itself uses JS/CSS v5.

The audit verifies identical mounted dialog/header/progress/footer nodes over Step 1→2 and Step 2→3, 230ms transition properties, progress completion and the reduced-motion branch. It checks the exact SVG path and wordmark animation on the shell and wizard, plus the shell brand across responsive viewport/theme coverage.

### Cancellation confirmation

The confirmation text now names the specific appointment, doctor, date and time before the destructive action is confirmed. The synthetic check verified: Dr. Maya Hassan, 28 November 2026, 10:30 AM-11:00 AM.

## Final verification

The final Playwright run passed **319/319 assertions across 26 browser states**. Four axe WCAG 2.0/2.1 A/AA scans reported zero violations. There were 77 mocked API requests and 9 intercepted POST requests; zero external, failed or unexpected API requests, page errors or unexpected console errors. One application diagnostic was expected and captured separately while testing the failed-request state. No screenshots were created.

The test runner covers desktop, laptop, tablet, 390px mobile and 320px narrow layouts in dark/light themes; wizard first-open and fit at desktop and 320px; backed-up/current Step 1/2 gap comparison; persistent-shell step transitions; progress fill/checkmarks; reduced motion; exact shared branding and hover animation; loading/empty/error lifecycle; calendar eligibility/export; the synthetic taken slot; cancellation text; and source encoding.

Evidence:
`C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase2-appointments-20261122\qa\patient-appointments-phase2-browser-audit.json`

Runner:
`D:\dev-toolkit\scripts\healthbridge-patient-appointments-phase2-audit-20261122.mjs`

## Scope

No real booking, cancellation, rating or database write was submitted. All browser API responses and POSTs were intercepted by synthetic fixtures. No PHP, API, service, database, authentication, session, CSRF, RBAC or booking-rule behavior was changed. No screenshots, staging, commits, pushes or deployment were performed.

Legacy references on this page: none.
