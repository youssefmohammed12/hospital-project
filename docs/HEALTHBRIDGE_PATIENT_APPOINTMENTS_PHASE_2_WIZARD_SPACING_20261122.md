# HealthBridge Patient Appointments — Booking Wizard Spacing and Focus Audit
**Date:** 22 November 2026  
**Route:** `pages/patient/appointments.html`  
**Status:** Implemented and verified locally  
**Requested viewport:** 1920 × 858

## Department tile and Step 1/2 content rhythm

Department tiles use centred content, 12px internal padding and a consistent 12px row/column gap. The nine-department fixture renders five rows. Measured tile height is **83.83px** with balanced **12.97px** top and bottom content insets.

A backed-up pre-follow-up replay was used to measure the original Step 1 and Step 2 gaps at the same 1920 × 858 viewport and with the same fixture (nine departments, five Cardiology doctors ending with Dr. Sherif Amin). The source replay measured **14.85px** from the last content row to the first footer button in both steps. That reproduction did not show the tall blank band described in the owner's screenshots; the pre-change source/fixture gap was already close to the 12–15px rhythm.

The revised structure removes the footer's automatic top margin and places the footer outside the scrollable body. The final measured gaps are now explicit and do not stretch with available space:

| State | Pre-follow-up source replay | Current layout | Current visible gap |
|---|---:|---:|---|
| Step 1 — last department row to Continue | 14.85px | 15.97px | ~16px |
| Step 2 — Dr. Sherif Amin row to Back / Continue | 14.85px | 15.92px | ~16px |

The exact larger blank band in the owner screenshots was not reproduced by the saved local source and representative fixture. The new layout nevertheless removes the flexible footer spacer and fixes the content-to-button rhythm at approximately 16px.

## Header and footer rhythm

The subtitle-to-header-divider gap measures **15.97px** and the last progress-label-to-STEP-label gap measures **15.97–16.08px**. Both align with the requested ~16px rhythm.

All wizard footers use **14px top and bottom padding**. The divider-to-button gap measures approximately **14px**, and the button-to-panel-bottom gap approximately **15px**. The footer is now a stable sibling below the scrollable content body rather than a footer that scrolls with the step.

## Step 3 — Date and time

The empty availability message clears the footer divider by **17.92px**. The time-slot grid uses consistent **12px row and column gaps**. A synthetic 16-slot response creates four full rows; all four rows fit at 1920 × 858 without an inner body scrollbar.

On entry to Step 3, keyboard focus moves directly to `#booking-date`. Other dialogs retain their normal initial focus behavior.

## Step 4 — Review

The label-to-value gap inside each review cell measures **6px**. The Notes textarea bottom clears the footer divider by **17px**. The Notes field is fully visible at the target viewport.

## Rendered measurements at 1920 × 858

Measurements are from browser-rendered DOM geometry using synthetic doctor and slot responses. Panel heights include the header, progress and footer.

| Wizard state | Panel height | Body client / scroll height | Inner scrollbar | Target viewport confirmed to fit |
|---|---:|---:|---|---:|
| Step 1 — 9 departments / 5 rows | 812.61px | 573 / 573px | No | 858px |
| Step 2 — 5 doctors | 718.27px | 477 / 477px | No | 858px |
| Step 3 — empty availability | 575.30px | 334 / 334px | No | 858px |
| Step 3 — 16 slots / 4 rows | 704.11px | 463 / 463px | No | 858px |
| Step 4 — Review with Notes | 681.73px | 441 / 441px | No | 858px |

**Result:** all four steps fit at **1920 × 858**. This is the requested viewport confirmed by rendered tests; the audit does not claim it is the absolute minimum possible height. The full five-row department fixture, four slot rows and Notes field fit without an inner wizard-body scrollbar.

## Verification

The complete Playwright audit passed **319/319 assertions across 26 browser states**. Four axe WCAG 2.0/2.1 A/AA scans reported zero violations. The run recorded 77 mocked API requests and 9 intercepted POST requests; zero external, failed or unexpected API requests; zero page errors and unexpected console errors. One expected handled diagnostic was recorded for the synthetic failed-request test. No screenshots were created.

Coverage includes the five responsive widths (1440, 1024, 768, 390 and 320px), dark/light list states, search visibility and behavior, all existing booking/reschedule contracts, state lifecycle, wizard first-open and 1920 × 858 fit, the baseline/current Step 1/2 gap comparison, transition continuity and reduced motion, shared patient brand checks, Step 3 date focus, taken slots and the existing Phase 2 checks. No real patient record or appointment was changed.

Runner:
`D:\dev-toolkit\scripts\healthbridge-patient-appointments-phase2-audit-20261122.mjs`

Evidence:
`C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase2-appointments-20261122\qa\patient-appointments-phase2-browser-audit.json`

## Scope and gate

The Appointments route and shared patient shell were updated locally. No PHP/API contract, database, authentication, session, CSRF, RBAC or booking rule was changed. No real patient write, screenshot, staging, commit, push or deployment was performed. Phase 2 remains awaiting owner acceptance; Phase 3 remains paused.

Legacy references on this page: none.
