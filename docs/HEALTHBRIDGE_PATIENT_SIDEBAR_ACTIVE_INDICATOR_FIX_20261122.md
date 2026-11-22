# HealthBridge — Patient sidebar active-tab alignment fix
Date: 22 November 2026

## Symptom and cause
The active vertical marker could appear above or detached from the selected patient navigation link. The shell calculated the marker position with `active.offsetTop - nav.offsetTop`. These offsets use different offset-parent coordinate systems, so subtracting them produced an incorrect (including negative) value such as `-61px`.

## Fix
- Calculate the active link's top position relative to the navigation itself using its and the nav's `getBoundingClientRect()` values, adding `nav.scrollTop`.
- Apply the same coordinate calculation when positioning the previous route before the transition, so route-to-route animation does not reintroduce the offset error.
- Run the initial measurement synchronously after the sidebar is mounted; the old requestAnimationFrame-only initialization could briefly leave the marker unset during first paint. Resize recalculation remains enabled.
- Bumped `patient-shell.js?v=1` to `patient-shell.js?v=2` on all nine patient workspace routes.

## Validation
- JavaScript syntax check passed for the updated `patient-shell.js` and the dedicated Playwright audit runner.
- Browser audit: **54/54 assertions PASS**, across all nine patient routes × 1440/390/320px × dark/light.
- For each main navigation route, the inline `--patient-active-y` value matched the selected link's measured top relative to `.patient-sidebar-nav` within 1px and was non-negative.
- Settings was separately verified as the active footer link (it is intentionally outside the main tab indicator).
- Zero page errors, unexpected console errors, external requests, failed requests, unmocked POSTs or horizontal overflow.
- Identity, settings and dashboard API responses were intercepted with synthetic test data. This was browser-layout validation only; no real patient data was read or changed.

## Files changed
- `assets/public-v2/js/core/patient-shell.js`
- `pages/patient/dashboard.html`
- `pages/patient/appointments.html`
- `pages/patient/downloads.html`
- `pages/patient/history.html`
- `pages/patient/prescriptions.html`
- `pages/patient/profile.html`
- `pages/patient/insights.html`
- `pages/patient/notifications.html`
- `pages/patient/settings.html`

## Evidence and rollback
- Runner: `D:\dev-toolkit\scripts\healthbridge-patient-sidebar-indicator-fix-audit-20261122.mjs`
- Evidence: `C:\xampp85\migration-backups\healthbridge\patient-dashboard-phase0-correction-20261122\qa\patient-sidebar-indicator-fix.json`
- Before/after source copies and SHA-256 manifest: `C:\xampp85\migration-backups\healthbridge\20261122-sidebar-indicator-fix\`

No PHP/API/database/auth/session/CSRF/RBAC changes. No screenshots, staging, commit, push or deployment. Patient Dashboard Phase 1 remains paused pending owner approval.

Legacy references on this page: none.
