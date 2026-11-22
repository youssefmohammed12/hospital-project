# HealthBridge — Patient Dashboard Phase 1 result
Date: 22 November 2026
Status: Phase 1 approved by owner. The booking-status clarification follow-up is implemented locally; Phase 2 remains paused.

## Objective and scope

After reviewing the completed Phase 0 shell and the owner-approved Patient Dashboard Improvement Plan, Phase 1 replaces the restored legacy-style dashboard body with a concise patient-first overview. The existing PHP-rendered architecture, Public V2 runtime, patient shell, API routes and server-side contracts remain in place.

This remains a bounded patient-dashboard correction. The existing POST endpoint, request payload, database schema/data, authentication/session/CSRF/authorization contracts and booking-open/closed decisions remain unchanged. PatientPortalService now includes additive `closed_reason`, `day_name` and `operating_days` metadata in the existing overview response so the indicator can explain the existing weekend and outside-hours rules.

## What changed

### 1. Patient greeting and a single booking-status signal
- Dashboard now opens with the patient's first name, time-appropriate greeting and current date.
- Added one patient-readable status signal for appointment booking availability, with booking hours when the existing overview response supplies them.
- The status is deliberately about **appointment booking**, not whether the hospital building or clinical services are open. The server rule is Monday–Friday within the configured hours; Saturday and Sunday are closed even during those clock hours. The indicator now says “Appointment booking is closed today · Sunday” (or Saturday) for weekend closures, and clarifies an outside-hours closure separately. The hours line explicitly identifies Monday–Friday.

### 2. Next appointment — never show a past slot
- The page uses the existing dashboard response and inspects the appointment timeline's upcoming/rescheduled collections, with the existing overview appointment as a fallback candidate.
- It rejects cancelled, completed and missed entries, invalid dates, past dates and same-day slots whose scheduled time has already passed.
- Remaining candidates are sorted by their actual scheduled date/time, and the earliest future appointment is selected.
- If none qualifies, the dashboard shows a clear “No upcoming appointments” state and a link to appointments.
- For a pending reschedule request, the card continues to show the currently scheduled slot and explains that the requested change is awaiting a response. The proposed replacement date/time is not presented as a confirmed appointment.
- The existing endpoint and POST request payload remain unchanged. The overview DTO gained three additive, backward-compatible metadata fields for closure reason, current day name and operating days; no caller needs to send anything new.

### 3. Actionable updates
- Dashboard displays up to three relevant patient alerts, with the existing destinations.
- The separate booking-status signal is not repeated as a “Hospital Closed” alert.
- An appointment-today alert is suppressed when no valid future appointment today remains.

### 4. Small, explained snapshot
The previous broad KPI collection is replaced by three account-record summaries:
- Visits completed — appointments marked complete.
- Active prescriptions — prescriptions currently marked active in the patient's records.
- Profile details — percentage of account/profile information on file.

The snapshot includes an explicit note that these are account-record summaries, not a medical assessment. The old appointment/doctor/notification counts were removed from the Dashboard rather than shown without patient-useful context.

### 5. Focused quick actions and one activity feed
- Three direct links remain: Appointments, Medical History and Prescriptions.
- One recent-activity feed displays at most three items.
- Removed the duplicated care-team, latest-prescription, notification, downloads and health-insights preview widgets from this page. Their dedicated routes remain in the patient sidebar; no page route was removed.

### 6. Loading, error and motion states
- Added a compact loading skeleton and a recoverable load-error state with a retry action.
- The new overview content enters with a small staggered rise/fade.
- Reduced-motion preferences suppress the new page entrance and card/link transitions.

### 7. Shared patient-header accessibility corrections
QA exposed two inherited patient-shell issues while testing the new dashboard:
- The notification badge had a CSS display rule that overrode its HTML `hidden` attribute, leaving an empty zero badge visible. The hidden state is now explicitly respected.
- The mobile account control previously removed the patient name from the accessibility tree when visually hiding the name. The name remains available to assistive technology while the visual header stays compact.

These shell corrections were regression-tested across all nine patient workspace routes.

## Files changed

- `pages/patient/dashboard.html`
- `assets/public-v2/js/pages/patient-dashboard.js`
- `assets/public-v2/css/pages/patient-dashboard.css`
- `assets/public-v2/js/core/patient-shell.js`
- `services/PatientPortalService.php` (additive booking-status explanation metadata; no schema or booking-rule change)
- `tools/test_patient_portal_booking_status_reason.php` (isolated, database-free rule tests)
- `pages/patient/appointments.html`
- `pages/patient/downloads.html`
- `pages/patient/history.html`
- `pages/patient/prescriptions.html`
- `pages/patient/profile.html`
- `pages/patient/insights.html`
- `pages/patient/notifications.html`
- `pages/patient/settings.html`

Asset references:
- All nine patient routes: `patient-dashboard.css?v=6`
- All nine patient routes: `patient-shell.js?v=2`
- Dashboard only: `patient-dashboard.js?v=3` (v3 adds the booking-closure reason and operating-day explanation)

The superseded Dashboard CSS presentation block was replaced in place; shell styles and Settings-specific styles remain. The dashboard-specific controller was rewritten rather than extending the retired multi-widget implementation. No legacy assets were reattached.

## Validation evidence

### Dashboard-specific browser audit — PASS
Runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-phase1-audit-20261122.mjs`

Evidence: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase1-20261122\qa\patient-dashboard-phase1.json`

- **386/386 assertions passed** across 16 browser states.
- Responsive matrix: 1440, 1024, 768, 390 and 320 pixels × dark/light (10 states).
- Five additional behavior scenarios: same-day future appointment, same-day past appointment excluded, pending reschedule retaining its original slot, no future appointment, and booking-hours closed copy.
- One reduced-motion scenario; the new content entrance and card/link transitions were confirmed suppressed.
- **7 axe WCAG 2.0/2.1 A/AA scans; zero violations.**
- Zero browser exceptions/console errors, external requests, failed requests, unexpected POSTs or horizontal overflow.
- The existing dashboard aggregate was requested exactly once per test state using its unchanged POST route and empty JSON body.

### Nine-page shell regression — PASS
Runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-phase1-shell-regression-20261122.mjs`

Evidence: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase0-correction-20261122\qa\patient-dashboard-phase1-shell-regression.json`

- **54/54 assertions passed** across all nine patient workspace pages × 1440/390/320 pixels × dark/light.
- All nine pages rendered the V2 shell; the active sidebar marker aligned with the active main tab, and Settings retained its expected footer active state.
- Zero browser errors, external/failed requests, unmocked POSTs or horizontal overflow.

### Source and cache checks — PASS
- `node --check` passed for the updated patient shell and dashboard controller.
- All nine route HTML files were checked for the current CSS and shell references.
- Dashboard controller reference is present only on Dashboard; Settings controller remains only on Settings.
- Before/after SHA-256 snapshots cover all 12 changed source files:
  `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase1-20261122\source-before\`
  `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase1-20261122\source-after\`
  with manifests `source-before-sha256.json` and `source-after-sha256.json`.

### Owner-approved booking-status clarification follow-up — PASS

The owner approved Phase 1 and reported that the booking status omitted the weekend-closure reason. The server intentionally closes booking Saturday/Sunday regardless of the configured clock hours; the app timezone is `Africa/Cairo`. The indicator now explains weekend closures by day name, separates weekday outside-hours closures, and labels configured times as Mon–Fri. Existing booking logic, endpoint and POST payload remain unchanged; the overview DTO adds backward-compatible `closed_reason`, `day_name` and `operating_days` fields.

- Browser regression: **429/429 assertions** across 17 states; 8 axe WCAG A/AA scans, zero violations. Runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-booking-status-audit-20261122.mjs`; evidence: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-booking-status-clarification-20261122\qa\booking-status-clarification.json`.
- PHP booking-status rule test: **6 cases / 36 assertions PASS**, including Sunday 09:22 (weekend closure), Monday 09:22 (open), before opening and at the closing boundary. Test: `tools/test_patient_portal_booking_status_reason.php`; captured output under the same backup's `qa/php-booking-status-unit-test.txt`.
- Follow-up detail: `docs/HEALTHBRIDGE_PATIENT_DASHBOARD_BOOKING_STATUS_CLARIFICATION_20261122.md`.

## Test-data boundary and review gate

All Dashboard API responses and current-user responses in these automated browser audits were intercepted and replaced with synthetic test fixtures. The tests validate frontend selection, rendering, responsiveness and accessibility; they do **not** prove the actual signed-in demo patient's database records or appointments. No patient data was written or altered.

No screenshots were taken. No staging, commit, push, deployment, database migration, schema change or appointment-rule change occurred. The overview response only gained additive status metadata.

**Owner review gate:** Phase 1 has been approved by the owner. This clarification resolves the reported booking-hours ambiguity. Phase 2 remains paused and still requires its own explicit approval.

Legacy references on this page: none.
