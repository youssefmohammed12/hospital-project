# HealthBridge — Patient Dashboard booking-status clarification
Date: 22 November 2026
Status: Implemented and locally tested. Phase 1 remains approved; Phase 2 has not started.

## Owner report and diagnosis

The owner reported that the status said “Appointment booking is closed” at 09:22 while the configured hours were 8:00 AM–10:00 PM.

The implementation was checked against the actual PHP service rule. The application timezone is `Africa/Cairo`. At the time of verification the server reported Sunday, 22 November 2026, 09:34:40. The configured clock time was inside the listed window, but the existing availability rule explicitly closes booking on both Saturday and Sunday:

- Monday–Friday: booking is open only during the configured `appointment_open_time` and `appointment_close_time`.
- Saturday and Sunday: booking is closed regardless of the clock time.
- The configured hours are therefore operating-day hours, not a promise that booking is available seven days a week.

The status calculation itself was consistent with the existing booking rule. The defect was the indicator: it did not disclose the weekend closure and presented the time range without the days it applies to.

## Correction

The booking logic was intentionally preserved. The existing overview DTO now supplies three additive metadata fields in its nested `hospital` object:

- `closed_reason`: `weekend`, `outside_hours`, or `null` when open.
- `day_name`: the server's current weekday name.
- `operating_days`: `Monday-Friday`, matching the existing weekend restriction.

The Dashboard now renders:
- Weekend closure: **“Appointment booking is closed today · Sunday”** (or Saturday).
- Outside the configured hours on an operating day: **“Appointment booking is closed outside booking hours.”**
- Open state: **“Appointment booking is open now.”**
- Supporting hours line: **“Booking hours · Mon–Fri · 8:00 AM–10:00 PM”** using the actual configured times.

The indicator is now explicit about both the reason for closure and the operating days to which the hours apply.

The existing `POST api/patient/dashboard.php` route, request method, empty request payload and underlying booking rule remain unchanged. The overview response gains additive fields only. No database schema or records were changed.

## Changed project files

- `services/PatientPortalService.php` — isolated status builder and additive status metadata in both v1 and v2 overview DTOs.
- `assets/public-v2/js/pages/patient-dashboard.js` — render the explicit closure reason and operating-day hours.
- `pages/patient/dashboard.html` — Dashboard-only cache reference advanced to `patient-dashboard.js?v=3`.
- `tools/test_patient_portal_booking_status_reason.php` — new database-free PHP rule tests.

No other patient page route or stylesheet needed changing.

## Verification

### Backend booking-status rule unit test — PASS

Runner: `C:\xampp85\php\php.exe tools/test_patient_portal_booking_status_reason.php`

- 6 scenarios / 36 assertions passed.
- Sunday 09:22: closed with `closed_reason=weekend`, `day_name=Sunday`.
- Saturday 09:22: closed with weekend reason.
- Monday 09:22: open.
- Friday 21:59: open.
- Monday 07:59: closed with outside-hours reason.
- Monday 22:00: closed at the exclusive closing boundary.
- Uses explicit `Africa/Cairo` dates and does not connect to a database. Captured output: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-booking-status-clarification-20261122\qa\php-booking-status-unit-test.txt`.

### Browser verification — PASS

Runner: `D:\dev-toolkit\scripts\healthbridge-patient-dashboard-booking-status-audit-20261122.mjs`

Evidence: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-booking-status-clarification-20261122\qa\booking-status-clarification.json`

- **429/429 assertions passed** across 17 browser states.
- Includes dark/light responsive states and explicit Sunday-weekend and weekday-outside-hours display cases.
- 8 axe WCAG 2.0/2.1 A/AA scans; zero violations.
- Zero browser exceptions/console errors, external requests, failed requests, unexpected POSTs or horizontal overflow.
- Dashboard API/current-user responses were intercepted and synthetic. Browser QA verified the rendered metadata and copy; the PHP unit test independently verified the actual server-side rule.

### Static checks — PASS

- PHP lint passed for `PatientPortalService.php` and the new test.
- Node syntax check passed for the Dashboard controller and browser audit runner.
- XAMPP PHP timezone confirmed as `Africa/Cairo`.
- Dashboard controller is loaded only on Dashboard with cache reference `v=3`.

## Scope and safety

No DB connection or writes were made by the new PHP unit test. No schema migration, appointment booking rule change, API endpoint/payload change, auth/session/CSRF/RBAC change, screenshot, stage, commit, push or deployment occurred.

Phase 1 remains approved. Phase 2 has not started and still requires separate owner approval.

## Source safety copies

Pre-fix copies and SHA-256 manifest: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-booking-status-clarification-20261122\source-before\` and `source-before-sha256.json` (six existing files). Post-fix copies and SHA-256 manifest: `source-after\` and `source-after-sha256.json` (nine affected/current files, including the new PHP test). The browser audit runner is copied beside the evidence as `booking-status-browser-audit-runner.mjs`.

Legacy references on this page: none.
